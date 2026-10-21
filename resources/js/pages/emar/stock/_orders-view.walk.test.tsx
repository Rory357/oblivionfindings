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
        url: '/emar/stock/packs?view=orders',
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
    client_medication_id: 7,
    medication_name: 'Cefalexin 500 mg capsule',
    client_name: 'Aroha Ngata',
    pharmacy_name: 'Kōwhai Pharmacy',
    status: 'dispensed',
    controlled: false,
    quantity_ordered: 21,
    quantity_received: '0.00',
    quantity_dispensed: '21.00',
    needed_by: null,
    expected_delivery: '2026-10-21',
    order_notes: null,
    communication_method: null,
    communication_reference: null,
    communication_recorded_at: null,
    closure_reason: null,
    batch_number: 'CX5521',
    batch_expiry: '2027-02-28T00:00:00.000000Z',
    unit: 'capsules',
    received_display: '0',
    dispensed_display: '21',
    ...overrides,
});
const orders: SupplyOrder[] = [
    order({}),
    order({
        id: 39,
        client_medication_id: 8,
        medication_name: 'Levetiracetam 500 mg tablet',
        client_name: 'Tama Walker',
        status: 'part_received',
        quantity_ordered: 112,
        unit: 'tablets',
        received_display: '56',
        still_to_come: '56',
        expected_delivery: '2026-10-23',
    }),
    order({
        id: 41,
        medication_name: 'Losartan 50 mg tablet',
        status: 'submitted',
        unit: 'tablets',
        dispensed_display: null,
        batch_number: null,
        batch_expiry: null,
        expected_delivery: null,
        needed_by: '2026-10-24',
    }),
    order({
        id: 42,
        medication_name: 'Metformin 500 mg tablet',
        status: 'draft',
        unit: 'tablets',
        expected_delivery: null,
    }),
    order({
        id: 30,
        medication_name: 'Amoxicillin 250 mg capsule',
        status: 'cancelled',
        closure_reason: 'The doctor stopped it',
        closed_at: '2026-10-19T02:00:00Z',
    }),
];
const empty = { data: [], total: 0, links: [], last_page: 1 };
const detail = (id: number, name: string): ItemDetail =>
    ({
        id,
        name,
        client_id: 1,
        site_id: 1,
        client_name: 'Aroha Ngata',
        site_name: 'Kōwhai House',
        controlled: false,
        active: true,
        stock_id: 1,
        on_hand: 0,
        unit: 'capsules',
        reorder_level: null,
        last_counted_at: null,
        lots_started: true,
        pack_count: 0,
        next_batch: null,
        next_expiry: null,
        expired_packs: 0,
        state: 'out',
        days_supply: null,
        packs: [],
        outward: [],
    }) as StockItem as ItemDetail;

beforeEach(() => {
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => ({
            ok: true,
            json: async () => detail(7, 'Cefalexin 500 mg capsule'),
        })),
    );
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

const hub = () =>
    render(
        <StockHub
            items={empty}
            orders={{ data: orders, total: 5, links: [], last_page: 1 }}
            counts={empty}
            movements={empty}
            sites={[{ id: 1, name: 'Kōwhai House' }]}
            pharmacies={[]}
            filters={{ view: 'orders', search: '', site_id: null }}
            metrics={stockMeters()}
            can={{ manage: true, receive: true, controlled: true }}
            lots_enabled
        />,
    );
const section = (title: string) => screen.getByRole('region', { name: title });
const rowOf = (scope: HTMLElement, name: string) =>
    within(scope).getByText(name).closest('[role="row"]') as HTMLElement;

// The approved P06 Deliveries & orders view (1440-12).
describe('Stock hub › Deliveries & orders (P06)', () => {
    it('groups orders into the approved sections', () => {
        hub();
        expect(
            screen
                .getAllByRole('heading', { level: 2 })
                .map((heading) => heading.textContent),
        ).toEqual([
            'To receive',
            'Waiting on the pharmacy',
            'Drafts',
            'Closed',
        ]);
        expect(within(section('To receive')).getAllByRole('row')).toHaveLength(
            3,
        );
        expect(
            within(section('To receive')).getByText(
                '2 shown · Dispensed by the pharmacy, or part received',
            ),
        ).toBeInTheDocument();
    });

    it('uses the approved columns, quantities and state lines', () => {
        hub();
        const receive = section('To receive');
        const cefalexin = rowOf(receive, 'Cefalexin 500 mg capsule');
        expect(within(cefalexin).getByText('21 capsules')).toBeInTheDocument();
        expect(within(cefalexin).getByText('To receive')).toBeInTheDocument();
        expect(
            within(cefalexin).getByText(
                /^Kōwhai Pharmacy dispensed 21 capsules · batch CX5521, expires 02\/2027$/,
            ),
        ).toBeInTheDocument();
        const part = rowOf(receive, 'Levetiracetam 500 mg tablet');
        expect(
            within(part).getByText('56 tablets received'),
        ).toBeInTheDocument();
        expect(
            within(part).getByText(/^56 tablets still to come — expected /),
        ).toBeInTheDocument();
        expect(
            within(section('Closed')).getByText(
                /^Cancelled .+: “The doctor stopped it”$/,
            ),
        ).toBeInTheDocument();
        expect(
            within(section('Waiting on the pharmacy')).getByText('Sent'),
        ).toBeInTheDocument();
    });

    it('gives each order its next step', async () => {
        hub();
        expect(
            within(section('Waiting on the pharmacy')).getByRole('button', {
                name: 'Record dispensing',
            }),
        ).toBeInTheDocument();
        expect(
            within(section('Drafts')).getByRole('button', {
                name: 'Review and send',
            }),
        ).toBeInTheDocument();
        expect(
            within(section('Closed')).queryByRole('button', {
                name: /Receive|Record|Review/,
            }),
        ).toBeNull();
        fireEvent.click(
            within(
                rowOf(section('To receive'), 'Cefalexin 500 mg capsule'),
            ).getByRole('button', { name: 'Receive' }),
        );
        expect(
            await screen.findByText(
                'Receiving Cefalexin 500 mg capsule against order #40',
            ),
        ).toBeInTheDocument();
    });
});
