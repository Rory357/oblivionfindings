/* eslint-disable no-restricted-syntax -- Minimal test-only controls replace dialogs/network widgets to exercise command contracts. */
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { SupplyOrderDialog } from './_dialogs';
import type { SupplyOrder } from './_types';
vi.mock('../pharmacy/_dispatch', () => ({
    PharmacyDispatch: ({ onStatusChanged }: any) => (
        <button onClick={onStatusChanged}>Simulate pharmacy update</button>
    ),
}));
vi.mock('./_requests', () => ({
    useStockCommand: () => ({ saving: false, errors: {}, run: vi.fn() }),
}));
const order: SupplyOrder = {
    id: 12,
    client_medication_id: 4,
    medication_name: 'Fictional medicine',
    client_name: 'Fictional person',
    pharmacy_name: 'Fictional pharmacy',
    status: 'draft',
    controlled: false,
    quantity_ordered: 5,
    quantity_received: null,
    quantity_dispensed: null,
    needed_by: null,
    expected_delivery: null,
    order_notes: null,
    communication_method: null,
    communication_reference: null,
    communication_recorded_at: null,
    closure_reason: null,
    batch_number: null,
    batch_expiry: null,
};
afterEach(() => vi.unstubAllGlobals());
it('refreshes the canonical order after pharmacy acknowledgement before offering local actions', async () => {
    vi.stubGlobal(
        'fetch',
        vi
            .fn()
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({ orders: [order] }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    orders: [
                        {
                            ...order,
                            status: 'confirmed',
                            communication_reference: 'Accepted by pharmacy',
                        },
                    ],
                }),
            }),
    );
    render(
        <SupplyOrderDialog
            order={order}
            canManage
            onClose={vi.fn()}
            onSaved={vi.fn()}
            onReceive={vi.fn()}
        />,
    );
    expect(
        await screen.findByRole('button', { name: 'Record pharmacy contact' }),
    ).toBeInTheDocument();
    fireEvent.click(
        screen.getByRole('button', { name: 'Simulate pharmacy update' }),
    );
    expect(
        await screen.findByRole('button', {
            name: 'What the pharmacy dispensed',
        }),
    ).toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Record pharmacy contact' }),
    ).not.toBeInTheDocument();
    expect(screen.getByText('Accepted by pharmacy')).toBeInTheDocument();
});
it('removes stale mutation actions when the refreshed order is no longer readable', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false }));
    render(
        <SupplyOrderDialog
            order={order}
            canManage
            onClose={vi.fn()}
            onSaved={vi.fn()}
            onReceive={vi.fn()}
        />,
    );
    expect(
        await screen.findByText(/This order is unavailable/),
    ).toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Record pharmacy contact' }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Cancel order' }),
    ).not.toBeInTheDocument();
});
