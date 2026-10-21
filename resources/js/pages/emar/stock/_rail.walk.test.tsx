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
import type { Movement } from './_types';

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

const empty = { data: [], total: 0, links: [], last_page: 1 };
const moves: Movement[] = Array.from({ length: 10 }, (_, index) => ({
    id: index + 1,
    medication_id: 7,
    medication_name: `Synthetic medicine ${index + 1}`,
    client_name: 'Aroha Ngata',
    kind: 'received',
    quantity: '28.00',
    balance_before: '0.00',
    balance_after: '28.00',
    reason: 'Pharmacy order #31',
    notes: null,
    recorded_at: '2026-10-20T20:30:00Z',
    recorded_by_name: 'Rangi Parata',
}));
const hub = (view: 'stock' | 'movements', controlled = true) =>
    render(
        <StockHub
            items={empty}
            orders={empty}
            counts={empty}
            movements={{ data: moves, total: 40, links: [], last_page: 2 }}
            sites={[{ id: 1, name: 'Kōwhai House' }]}
            pharmacies={[]}
            filters={{ view, search: '', site_id: null }}
            metrics={stockMeters()}
            can={{ manage: true, receive: true, controlled }}
            lots_enabled
        />,
    );

// The approved P06 rail (README §2): Stock · Deliveries & orders · Counts ·
// Expiring · Removals, Controlled for controlled-medicine view, then Find.
describe('Stock hub rail (P06)', () => {
    it('matches the approved views and ends with Find', () => {
        hub('stock');
        expect(
            screen.getAllByRole('tab').map((tab) => tab.textContent),
        ).toEqual([
            'Stock',
            'Deliveries & orders',
            'Counts',
            'Expiring',
            'Removals',
            'Controlled',
        ]);
        expect(
            screen.getByRole('button', { name: 'Find a view' }),
        ).toBeInTheDocument();
    });

    it('shows recent movements under the stock list, newest first, with a way to all of them', () => {
        hub('stock');
        const recent = screen.getByRole('region', { name: 'Recent movements' });
        expect(within(recent).getAllByRole('row')).toHaveLength(9);
        expect(
            within(recent).getByText('Synthetic medicine 1', { exact: false }),
        ).toBeInTheDocument();
        expect(
            within(recent).queryByText('Synthetic medicine 9', {
                exact: false,
            }),
        ).toBeNull();
        fireEvent.click(
            within(recent).getByRole('button', {
                name: 'View all movements',
            }),
        );
        expect(router.get).toHaveBeenLastCalledWith(
            '/emar/stock/packs',
            expect.objectContaining({ view: 'movements' }),
            expect.anything(),
        );
    });

    it('keeps ?view=movements working under the Stock tab', () => {
        hub('movements', false);
        expect(screen.getByRole('tab', { name: 'Stock' })).toHaveAttribute(
            'aria-selected',
            'true',
        );
        expect(screen.getByText('All movements')).toBeInTheDocument();
        expect(screen.getByText('10 of 40 shown')).toBeInTheDocument();
        expect(
            screen.queryByRole('region', { name: 'Recent movements' }),
        ).toBeNull();
    });
});
