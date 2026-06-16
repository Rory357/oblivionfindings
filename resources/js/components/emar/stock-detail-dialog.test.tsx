import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

// Only the detail dialog touches Inertia (router.visit for the client/MAR jumps).
const visit = vi.fn();
vi.mock('@inertiajs/react', () => ({
    router: { visit: (...args: unknown[]) => visit(...args) },
}));

import type { StockRow } from '@/pages/emar/_stock-dialogs';
import { StockDetailDialog } from './stock-detail-dialog';

const baseItem: StockRow = {
    id: 1,
    medication_id: 10,
    medication_name: 'Paracetamol',
    medication_dose: '500mg',
    client_id: 7,
    client_name: 'Ada Lovelace',
    client_room: 'Room 3',
    mar_url: '/emar/mar?client_id=7',
    site_id: 2,
    site_name: 'Maple House',
    on_hand: 8,
    unit: 'tablets',
    reorder_level: 10,
    reorder_quantity: 30,
    last_counted_at: '2026-06-10T09:00:00+12:00',
    expiry_date: '2026-12-01',
    batch_number: 'B-123',
    supplier_name: 'PharmCo',
    controlled: false,
    storage_condition: 'ambient',
    requires_cold_chain: false,
    is_low: true,
    is_expired: false,
    is_expiring_soon: false,
    is_expiring_90: false,
    movements: [
        { id: 1, at: '2026-06-10T09:00:00+12:00', actor: 'Nurse Joy', type: 'received', summary: 'Stock received', delta: 30, unit: 'tablets' },
        { id: 2, at: '2026-06-11T09:00:00+12:00', actor: 'Nurse Joy', type: 'adjusted', summary: 'Damaged blister', delta: -2, unit: 'tablets' },
    ],
};

afterEach(() => {
    cleanup();
    visit.mockClear();
});

describe('StockDetailDialog', () => {
    it('renders the overview and fires the footer action callbacks', () => {
        const onAdjust = vi.fn();
        const onCount = vi.fn();
        const onOrder = vi.fn();
        render(
            <StockDetailDialog
                item={baseItem}
                openOrder={{ status: 'submitted', pharmacy_name: 'PharmCo', order_type: 'routine', quantity_ordered: 30, ordered_at: '2026-06-09' }}
                onClose={() => {}}
                onAdjust={onAdjust}
                onCount={onCount}
                onOrder={onOrder}
            />,
        );

        expect(screen.getAllByText('Paracetamol').length).toBeGreaterThan(0);
        expect(screen.getByText('Ada Lovelace')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: /Adjust stock/i }));
        fireEvent.click(screen.getByRole('button', { name: /Run count/i }));
        fireEvent.click(screen.getByRole('button', { name: /Order more/i }));
        expect(onAdjust).toHaveBeenCalledTimes(1);
        expect(onCount).toHaveBeenCalledTimes(1);
        expect(onOrder).toHaveBeenCalledTimes(1);
    });

    it('jumps to the client profile MAR tab from the footer', () => {
        render(<StockDetailDialog item={baseItem} onClose={() => {}} onAdjust={() => {}} onCount={() => {}} onOrder={() => {}} />);
        fireEvent.click(screen.getByRole('button', { name: /^Client$/i }));
        expect(visit).toHaveBeenCalledWith('/operations/clients/7?tab=mar');
    });

    it('shows the movement history on the activity tab', () => {
        render(<StockDetailDialog item={baseItem} onClose={() => {}} onAdjust={() => {}} onCount={() => {}} onOrder={() => {}} />);
        fireEvent.click(screen.getByText('Activity'));
        expect(screen.getByText('Damaged blister')).toBeTruthy();
        expect(screen.getByText(/\+30/)).toBeTruthy();
    });
});
