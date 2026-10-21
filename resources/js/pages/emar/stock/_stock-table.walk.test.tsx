import { router } from '@inertiajs/react';
import {
    cleanup,
    fireEvent,
    render,
    screen,
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
vi.mock('./_receive', () => ({
    ReceiveWizard: ({
        item,
        order,
    }: {
        item: ItemDetail;
        order?: SupplyOrder;
    }) => (
        <div>
            Receiving {item.name} against order #{order?.id}
        </div>
    ),
}));

const order = (overrides: Partial<SupplyOrder>): SupplyOrder => ({
    id: 40,
    client_medication_id: 2,
    medication_name: 'Cefalexin 500 mg capsule',
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
const row = (overrides: Partial<StockItem>): StockItem => ({
    id: 1,
    client_id: 1,
    site_id: 1,
    name: 'Synthetic medicine',
    client_name: 'Aroha Ngata',
    site_name: 'Kōwhai House',
    controlled: false,
    active: true,
    receivable: true,
    stock_id: 1,
    on_hand: 68,
    physical_on_hand: '68.00',
    usable_on_hand: '68.00',
    usable_display: '68',
    physical_display: '68',
    reorder_display: null,
    unit: 'tablets',
    reorder_level: null,
    last_counted_at: null,
    lots_started: true,
    pack_count: 2,
    usable_packs: 2,
    next_batch: 'MF2201',
    next_expiry: '2027-03-31',
    next_quantity: '40',
    next_expiry_in_days: 162,
    expired_packs: 0,
    expired_lots: [],
    state: 'ok',
    days_supply: null,
    open_order: null,
    count_to_sign_off: null,
    ...overrides,
});
const items: StockItem[] = [
    row({ id: 1, name: 'Metformin 500 mg tablet' }),
    row({
        id: 2,
        name: 'Cefalexin 500 mg capsule',
        on_hand: 0,
        usable_display: '0',
        physical_display: '0',
        pack_count: 0,
        usable_packs: 0,
        next_batch: null,
        next_expiry: null,
        next_quantity: null,
        next_expiry_in_days: null,
        state: 'out',
        open_order: order({}),
    }),
    row({
        id: 3,
        name: 'Losartan 50 mg tablet',
        client_name: 'Tama Walker',
        on_hand: 5,
        usable_display: '5',
        physical_display: '5',
        reorder_display: '7',
        pack_count: 1,
        usable_packs: 1,
        state: 'low',
        open_order: order({
            id: 41,
            client_medication_id: 3,
            status: 'draft',
            expected_delivery: null,
        }),
    }),
    row({
        id: 4,
        name: 'Insulin glargine 100 units/mL pen',
        usable_display: '2.5',
        physical_display: '2.5',
        unit: 'mL',
        pack_count: 1,
        usable_packs: 1,
    }),
    row({
        id: 5,
        name: 'Amoxicillin 250 mg capsule',
        on_hand: 0,
        usable_display: '0',
        physical_display: '6',
        pack_count: 1,
        usable_packs: 0,
        next_batch: null,
        next_expiry: null,
        state: 'out',
        expired_packs: 1,
        expired_lots: [
            {
                batch_number: 'IG7781',
                expiry_date: '2026-10-01',
                quantity: '6',
            },
        ],
    }),
    row({
        id: 6,
        name: 'Methylphenidate 10 mg tablet',
        client_name: 'Tama Walker',
        controlled: true,
    }),
];
const empty = { data: [], total: 0, links: [], last_page: 1 };
const hub = (total = items.length, hidden = 0) =>
    render(
        <StockHub
            items={{ data: items, total, links: [], last_page: 1 }}
            orders={empty}
            counts={empty}
            movements={empty}
            sites={[{ id: 1, name: 'Kōwhai House' }]}
            pharmacies={[]}
            filters={{ view: 'stock', search: '', site_id: null }}
            metrics={stockMeters({ controlled_hidden: hidden })}
            item_people={2}
            can={{ manage: true, receive: true, controlled: true }}
            lots_enabled
        />,
    );
const rowOf = (name: string) =>
    within(screen.getByRole('table'))
        .getByText(name)
        .closest('[role="row"]') as HTMLElement;

beforeEach(() => {
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => ({
            ok: true,
            json: async () => ({
                ...items[1],
                packs: [],
                outward: [],
            }),
        })),
    );
});
afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    vi.unstubAllGlobals();
});

// The approved P06 stock table (1440-06, README §2).
describe('Stock hub › Medicines held (P06)', () => {
    it('uses the approved columns and caption', () => {
        hub();
        const headers = within(screen.getByRole('table'))
            .getAllByRole('columnheader')
            .map((cell) => cell.textContent?.trim())
            .filter(Boolean);
        expect(headers).toEqual([
            'Medicine',
            'Person',
            'On hand',
            'Packs',
            'State',
            'Actions',
        ]);
        expect(screen.getByText('Medicines held')).toBeInTheDocument();
        expect(screen.getByText('6 shown · 2 people')).toBeInTheDocument();
    });

    it('keeps "of total" when the list is paged, and counts hidden controlled medicines', () => {
        hub(30, 2);
        expect(
            screen.getByText(
                '6 of 30 shown · 2 people · 2 controlled medicines not shown — needs controlled-medicine access',
            ),
        ).toBeInTheDocument();
    });

    it('shows whole numbers when whole and the decimal only when real', () => {
        hub();
        expect(
            within(rowOf('Metformin 500 mg tablet')).getByText('68 tablets'),
        ).toBeInTheDocument();
        expect(
            within(rowOf('Insulin glargine 100 units/mL pen')).getByText(
                '2.5 mL',
            ),
        ).toBeInTheDocument();
        // "0" is a real balance: never "None yet", and the held pack is shown.
        const expired = rowOf('Amoxicillin 250 mg capsule');
        expect(within(expired).getByText('0 tablets')).toBeInTheDocument();
        expect(
            within(expired).getByText('6 tablets physically held'),
        ).toBeInTheDocument();
        expect(screen.queryByText(/\.00/)).toBeNull();
    });

    it('puts packs, the state and its lines on each row', () => {
        hub();
        const metformin = rowOf('Metformin 500 mg tablet');
        expect(within(metformin).getByText('2 packs')).toBeInTheDocument();
        expect(
            within(metformin).getByText('First: 03/2027'),
        ).toBeInTheDocument();
        expect(within(metformin).getByText('In stock')).toBeInTheDocument();
        expect(within(metformin).getByText('Aroha Ngata')).toBeInTheDocument();

        const losartan = rowOf('Losartan 50 mg tablet');
        expect(within(losartan).getByText('Running low')).toBeInTheDocument();
        expect(
            within(losartan).getByText(
                '5 tablets left — at or below the reorder level (7)',
            ),
        ).toBeInTheDocument();
        expect(
            within(losartan).getByText(
                'Order drafted — not sent to the pharmacy yet (#41)',
            ),
        ).toBeInTheDocument();

        const amoxicillin = rowOf('Amoxicillin 250 mg capsule');
        expect(
            within(amoxicillin).getByText('Expired pack'),
        ).toBeInTheDocument();
        expect(
            within(amoxicillin).getByText(
                '6 tablets from IG7781 expired 10/2026 — take out of use',
            ),
        ).toBeInTheDocument();

        const cefalexin = rowOf('Cefalexin 500 mg capsule');
        expect(within(cefalexin).getByText('Delivery due')).toBeInTheDocument();
        expect(
            within(rowOf('Methylphenidate 10 mg tablet')).getByText(
                'In the controlled register',
            ),
        ).toBeInTheDocument();
    });

    it('gives each row its next step', async () => {
        hub();
        expect(
            within(rowOf('Losartan 50 mg tablet')).getByRole('button', {
                name: 'Send the order',
            }),
        ).toBeInTheDocument();
        expect(
            within(rowOf('Amoxicillin 250 mg capsule')).getByRole('button', {
                name: 'Remove expired',
            }),
        ).toBeInTheDocument();
        fireEvent.click(
            within(rowOf('Cefalexin 500 mg capsule')).getByRole('button', {
                name: 'Receive',
            }),
        );
        expect(
            await screen.findByText(
                'Receiving Cefalexin 500 mg capsule against order #40',
            ),
        ).toBeInTheDocument();
        expect(router.get).not.toHaveBeenCalled();
    });

    it('keeps right-click and the row menu', () => {
        hub();
        fireEvent.contextMenu(rowOf('Metformin 500 mg tablet'));
        expect(
            screen.getByRole('menuitem', { name: /Open the stock item/ }),
        ).toBeInTheDocument();
    });
});
