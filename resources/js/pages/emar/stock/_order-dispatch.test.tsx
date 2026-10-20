import { cleanup, render, screen, within } from '@testing-library/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import StockHub from './StockHub';
import type { SupplyOrder } from './_types';

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

afterEach(cleanup);

const order = (overrides: Partial<SupplyOrder>): SupplyOrder => ({
    id: 1,
    client_medication_id: 1,
    medication_name: 'Synthetic medicine',
    client_name: 'Synthetic person',
    pharmacy_name: 'Synthetic pharmacy',
    status: 'submitted',
    controlled: false,
    quantity_ordered: 28,
    quantity_received: null,
    quantity_dispensed: null,
    needed_by: '2026-10-22',
    expected_delivery: null,
    order_notes: null,
    communication_method: null,
    communication_reference: null,
    communication_recorded_at: null,
    closure_reason: null,
    batch_number: null,
    batch_expiry: null,
    ...overrides,
});
const empty = { data: [], total: 0, links: [], last_page: 1 };

describe('Stock hub › Deliveries & orders (EA-138)', () => {
    it('shows where a connected pharmacy send stands on the order row', () => {
        render(
            <StockHub
                items={empty}
                orders={{
                    data: [
                        order({
                            id: 1,
                            medication_name: 'Sent medicine',
                            dispatch: {
                                state: 'rejected',
                                label: 'Pharmacy declined',
                                short_label: 'Pharmacy declined',
                                tone: 'critical',
                                note: 'Contact the pharmacy and arrange the supply another way.',
                            },
                        }),
                        order({
                            id: 2,
                            medication_name: 'Unsent medicine',
                            dispatch: null,
                        }),
                    ],
                    total: 2,
                    links: [],
                    last_page: 1,
                }}
                counts={empty}
                movements={empty}
                sites={[]}
                pharmacies={[]}
                filters={{ view: 'orders', search: '', site_id: null }}
                metrics={{
                    tracked: 0,
                    out: 0,
                    expiring: 0,
                    orders: 2,
                    counts: 0,
                }}
                can={{ manage: false, receive: false, controlled: false }}
                lots_enabled
            />,
        );
        const sent = screen.getByText('Sent medicine').closest('[role="row"]')!;
        expect(
            within(sent as HTMLElement).getByTestId('pharmacy-dispatch-state'),
        ).toHaveTextContent('Pharmacy declined');
        const unsent = screen
            .getByText('Unsent medicine')
            .closest('[role="row"]')!;
        expect(
            within(unsent as HTMLElement).queryByTestId(
                'pharmacy-dispatch-state',
            ),
        ).toBeNull();
    });
});
