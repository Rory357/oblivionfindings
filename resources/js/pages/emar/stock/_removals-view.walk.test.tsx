import { cleanup, render, screen, within } from '@testing-library/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import StockHub from './StockHub';
import { stockMeters } from './__fixtures__/meters';
import type { ExpiryPack, Movement } from './_types';

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    usePage: () => ({
        url: '/emar/stock/packs?view=removals',
        props: { auth: { can: { medications: {} } } },
    }),
    router: { get: vi.fn(), reload: vi.fn(), visit: vi.fn() },
}));

afterEach(cleanup);

const expired: ExpiryPack = {
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
};
const move = (overrides: Partial<Movement>): Movement => ({
    id: 1,
    medication_id: 4,
    medication_name: 'Paracetamol 500 mg tablet',
    client_name: 'Mele Fifita',
    kind: 'damaged',
    quantity: '2.00',
    quantity_display: '2',
    change_display: '-2',
    unit: 'tablets',
    balance_before: '10.00',
    balance_after: '8.00',
    reason: 'Dropped on the bathroom floor',
    notes: null,
    recorded_at: '2026-10-17T05:10:00Z',
    recorded_by_name: 'Daniel Ahn',
    ...overrides,
});
const empty = { data: [], total: 0, links: [], last_page: 1 };
const hub = (manage: boolean, packs: ExpiryPack[] = [expired]) =>
    render(
        <StockHub
            items={empty}
            orders={empty}
            counts={empty}
            movements={{
                data: [
                    move({}),
                    move({
                        id: 2,
                        medication_name: 'Losartan 25 mg tablet',
                        client_name: 'Aroha Ngata',
                        kind: 'returned_pharmacy',
                        quantity_display: '3',
                        reason: 'Returned after the dose changed to 50 mg',
                        recorded_by_name: 'Jordan Tipene',
                    }),
                ],
                total: 2,
                links: [],
                last_page: 1,
            }}
            expiry_packs={{ data: packs, more: false }}
            sites={[{ id: 1, name: 'Kōwhai House' }]}
            pharmacies={[]}
            filters={{ view: 'removals', search: '', site_id: null }}
            metrics={stockMeters()}
            can={{ manage, receive: true, controlled: true }}
            lots_enabled
        />,
    );
const section = (title: string) => screen.getByRole('region', { name: title });

// The approved P06 Removals view (1440-16).
describe('Stock hub › Removals (P06)', () => {
    it('lists expired packs still held, then what was removed or returned', () => {
        hub(true);
        expect(
            screen
                .getAllByRole('heading', { level: 2 })
                .map((heading) => heading.textContent),
        ).toEqual(['To remove', 'Removed and returned']);
        const toRemove = section('To remove');
        expect(
            within(toRemove).getByText(
                '— batch LT0712, expired 09/2026, 4 tablets',
                { exact: false },
            ),
        ).toBeInTheDocument();
        expect(
            within(toRemove).getByRole('button', { name: 'Remove' }),
        ).toBeInTheDocument();
        const done = section('Removed and returned');
        expect(
            within(done).getByText('2 shown · with the reason, kept'),
        ).toBeInTheDocument();
        expect(
            within(done).getByText('Damaged or dropped · 2 tablets'),
        ).toBeInTheDocument();
        expect(
            within(done).getByText('Returned to the pharmacy · 3 tablets'),
        ).toBeInTheDocument();
        expect(
            within(done).getByText('Dropped on the bathroom floor'),
        ).toBeInTheDocument();
    });

    it('tells others who removes it, and says when nothing is waiting', () => {
        hub(false);
        expect(
            within(section('To remove')).getByText('The house lead removes it'),
        ).toBeInTheDocument();
        cleanup();
        hub(true, []);
        expect(
            within(section('To remove')).getByText('Nothing to remove'),
        ).toBeInTheDocument();
    });
});
