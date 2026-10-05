import type { StockRow } from '@/pages/emar/_stock-dialogs';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { StockInventoryTable } from './_inventory-table';

afterEach(cleanup);

function item(id: number, overrides: Partial<StockRow> = {}): StockRow {
    return {
        id,
        medication_id: id,
        medication_name: `Medicine ${String(id).padStart(2, '0')}`,
        medication_dose: '5 mg',
        client_id: id,
        client_name: `Person ${String(id).padStart(2, '0')}`,
        client_room: null,
        mar_url: null,
        site_id: 1,
        site_name: 'Kauri House',
        on_hand: 30,
        unit: 'tablets',
        reorder_level: 5,
        reorder_quantity: 30,
        last_counted_at: null,
        expiry_date: null,
        batch_number: null,
        supplier_name: null,
        controlled: false,
        storage_condition: 'ambient',
        requires_cold_chain: false,
        is_low: false,
        is_expired: false,
        is_expiring_soon: false,
        is_expiring_90: false,
        movements: [],
        ...overrides,
    };
}
const noActions = () => [];

describe('Desktop stock inventory', () => {
    it('bounds the list to ten rows and reaches every record through labelled pages', () => {
        const rows = Array.from({ length: 23 }, (_, index) =>
            item(index + 1),
        ).reverse();
        render(
            <StockInventoryTable
                rows={rows}
                actionsFor={noActions}
                onOpen={vi.fn()}
            />,
        );
        expect(screen.getAllByRole('table')).toHaveLength(1);
        expect(screen.getAllByRole('row')).toHaveLength(11);
        expect(screen.getByRole('status')).toHaveTextContent(
            '1–10 of 23 items',
        );
        expect(
            screen.getAllByRole('button', { name: 'Previous stock page' })[0],
        ).toBeDisabled();
        expect(screen.queryByText('Medicine 11')).not.toBeInTheDocument();
        fireEvent.click(
            screen.getAllByRole('button', { name: 'Next stock page' })[0],
        );
        expect(screen.getByRole('status')).toHaveTextContent(
            '11–20 of 23 items',
        );
        expect(screen.getByText('Medicine 11')).toBeVisible();
        fireEvent.click(
            screen.getAllByRole('button', { name: 'Next stock page' })[0],
        );
        expect(screen.getByRole('status')).toHaveTextContent(
            '21–23 of 23 items',
        );
        expect(screen.getAllByRole('row')).toHaveLength(4);
        expect(screen.getByText('Medicine 23')).toBeVisible();
        expect(
            screen.getAllByRole('button', { name: 'Next stock page' })[0],
        ).toBeDisabled();
        fireEvent.click(
            screen.getAllByRole('button', { name: 'Previous stock page' })[0],
        );
        expect(screen.getByText('Medicine 11')).toBeVisible();
    });

    it('opens the correct person-owned record by keyboard and through its action menu', () => {
        const rows = [item(1), item(2)];
        const open = vi.fn();
        const order = vi.fn();
        render(
            <StockInventoryTable
                rows={rows}
                onOpen={open}
                actionsFor={(row) => [
                    { label: 'Order more', onClick: () => order(row) },
                ]}
            />,
        );
        const row = screen.getByText('Medicine 02').closest('[role="row"]')!;
        expect(within(row as HTMLElement).getByText('Person 02')).toBeVisible();
        expect(
            within(row as HTMLElement).getByText('Kauri House'),
        ).toBeVisible();
        fireEvent.keyDown(row, { key: 'Enter' });
        expect(open).toHaveBeenCalledExactlyOnceWith(rows[1]);
        fireEvent.contextMenu(row, { clientX: 50, clientY: 60 });
        fireEvent.click(screen.getByRole('menuitem', { name: 'Order more' }));
        expect(order).toHaveBeenCalledExactlyOnceWith(rows[1]);
        expect(open).toHaveBeenCalledTimes(1);
        expect(
            within(row as HTMLElement).getByRole('button', {
                name: /actions/i,
            }),
        ).toBeVisible();
    });

    it('keeps unknown balances honest and expiry dates independent of browser timezone', () => {
        render(
            <StockInventoryTable
                rows={[
                    item(1, {
                        on_hand: null,
                        expiry_date: '2026-10-06',
                        controlled: true,
                        requires_cold_chain: true,
                    }),
                ]}
                actionsFor={noActions}
                onOpen={vi.fn()}
            />,
        );
        expect(screen.getAllByText('Unknown')).toHaveLength(2);
        expect(screen.queryByText('In stock')).not.toBeInTheDocument();
        expect(screen.queryByText('0 tablets')).not.toBeInTheDocument();
        expect(screen.getByText('6 Oct 2026')).toBeVisible();
        expect(screen.getByText('CD')).toBeVisible();
        expect(screen.getByLabelText('Cold chain')).toBeVisible();
    });

    it('returns to a valid page when refreshed records shrink and resets on changed filters', () => {
        const rows = Array.from({ length: 23 }, (_, index) => item(index + 1));
        const props = { actionsFor: noActions, onOpen: vi.fn() };
        const { rerender } = render(
            <StockInventoryTable key="all" rows={rows} {...props} />,
        );
        fireEvent.click(
            screen.getAllByRole('button', { name: 'Next stock page' })[0],
        );
        fireEvent.click(
            screen.getAllByRole('button', { name: 'Next stock page' })[0],
        );
        rerender(
            <StockInventoryTable
                key="all"
                rows={rows.slice(0, 12)}
                {...props}
            />,
        );
        expect(screen.getByRole('status')).toHaveTextContent(
            '11–12 of 12 items',
        );
        rerender(
            <StockInventoryTable
                key="low"
                rows={rows.slice(0, 11)}
                {...props}
            />,
        );
        expect(screen.getByRole('status')).toHaveTextContent(
            '1–10 of 11 items',
        );
        expect(screen.getByText('Medicine 01')).toBeVisible();
    });
});
