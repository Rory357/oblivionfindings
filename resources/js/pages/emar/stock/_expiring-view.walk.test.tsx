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
import type { ExpiryPack, ItemDetail } from './_types';

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    usePage: () => ({
        url: '/emar/stock/packs?view=expiring',
        props: { auth: { can: { medications: {} } } },
    }),
    router: { get: vi.fn(), reload: vi.fn(), visit: vi.fn() },
}));
vi.mock('./_dialogs', async (original) => ({
    ...(await original<typeof import('./_dialogs')>()),
    MovementDialog: ({ item, mode }: { item: ItemDetail; mode: string }) => (
        <div>
            {mode} for {item.name}
        </div>
    ),
}));

const pack = (overrides: Partial<ExpiryPack>): ExpiryPack => ({
    id: 1,
    medication_id: 3,
    medication_name: 'Levothyroxine 50 microgram tablet',
    client_name: 'Grace Liu',
    controlled: false,
    batch_number: 'LT0712',
    expiry_date: '2026-09-30',
    days: -8,
    quantity: '4',
    unit: 'tablets',
    ...overrides,
});
const packs = [
    pack({}),
    pack({
        id: 2,
        medication_id: 4,
        medication_name: 'Levetiracetam 500 mg tablet',
        client_name: 'Tama Walker',
        batch_number: 'LV101',
        expiry_date: '2026-10-27',
        days: 7,
        quantity: '6',
    }),
    pack({
        id: 3,
        medication_id: 5,
        medication_name: 'Insulin glargine 100 units/mL pen',
        client_name: 'Aroha Ngata',
        batch_number: 'IG7781',
        expiry_date: '2026-11-11',
        days: 22,
        quantity: '1',
        unit: 'pens',
    }),
];
const empty = { data: [], total: 0, links: [], last_page: 1 };
const hub = (manage: boolean) =>
    render(
        <StockHub
            items={empty}
            orders={empty}
            counts={empty}
            movements={empty}
            expiry_packs={{ data: packs, more: false }}
            sites={[{ id: 1, name: 'Kōwhai House' }]}
            pharmacies={[]}
            filters={{ view: 'expiring', search: '', site_id: null }}
            metrics={stockMeters()}
            can={{ manage, receive: true, controlled: true }}
            lots_enabled
        />,
    );
const section = (title: string) => screen.getByRole('region', { name: title });

beforeEach(() => {
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => ({
            ok: true,
            json: async () => ({
                id: 3,
                name: 'Levothyroxine 50 microgram tablet',
                packs: [],
                outward: [],
            }),
        })),
    );
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

// The approved P06 Expiring view (1440-15).
describe('Stock hub › Expiring (P06)', () => {
    it('bands packs into expired, within 7 days and within 30 days', () => {
        hub(true);
        expect(
            screen
                .getAllByRole('heading', { level: 2 })
                .map((heading) => heading.textContent),
        ).toEqual([
            'Expired — take out of use',
            'Within 7 days',
            'Within 30 days',
        ]);
        const expired = section('Expired — take out of use');
        expect(within(expired).getByText('LT0712')).toBeInTheDocument();
        expect(
            within(expired).getByText('Expires 09/2026'),
        ).toBeInTheDocument();
        expect(within(expired).getByText('4 tablets')).toBeInTheDocument();
        expect(
            within(expired).getByText('Expired 8 days ago'),
        ).toBeInTheDocument();
        expect(
            within(section('Within 7 days')).getByText('In 7 days'),
        ).toBeInTheDocument();
        expect(
            within(section('Within 30 days')).getByText('1 pen'),
        ).toBeInTheDocument();
    });

    it('lets the house lead remove an expired pack, and tells others to set it aside', async () => {
        hub(true);
        fireEvent.click(
            within(section('Expired — take out of use')).getByRole('button', {
                name: 'Remove',
            }),
        );
        expect(
            await screen.findByText(
                'move for Levothyroxine 50 microgram tablet',
            ),
        ).toBeInTheDocument();
        cleanup();
        hub(false);
        expect(
            within(section('Expired — take out of use')).getByText(
                'Set aside for the house lead',
            ),
        ).toBeInTheDocument();
    });
});
