import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { controlledColumns, PharmacyOrderDetail } from './_hub-tables';
import type { ControlledRegisterRow, PharmacyOrderRow } from './_hub-types';

afterEach(cleanup);
describe('Stock evidence display', () => {
    it('does not claim reconciliation from the absence of a discrepancy record', () => {
        const status = controlledColumns.find(
            (column) => column.key === 'reconciliation',
        )!;
        const row = {
            discrepancy: null,
            last_check_at: null,
            last_check_witness: null,
            register_balance: 20,
        } as ControlledRegisterRow;
        const { rerender } = render(<>{status.cell(row)}</>);
        expect(screen.queryByText('Reconciled')).not.toBeInTheDocument();
        expect(screen.getByText('Check not recorded')).toBeVisible();
        rerender(<>{status.cell({ ...row, discrepancy: -2 })}</>);
        expect(screen.getByText('Discrepancy -2')).toBeVisible();
        rerender(
            <>
                {status.cell({
                    ...row,
                    last_check_at: '2026-10-05T01:00:00Z',
                    last_check_witness: 'Test Witness',
                })}
            </>,
        );
        expect(screen.queryByText('Reconciled')).not.toBeInTheDocument();
        expect(screen.getByText('Check recorded')).toBeVisible();
        rerender(
            <>
                {status.cell({
                    ...row,
                    last_check_at: '2026-10-05T01:00:00Z',
                    last_check_witness: 'Test Witness',
                    register_balance: null,
                })}
            </>,
        );
        expect(screen.getByText('Check incomplete')).toBeVisible();
    });

    it('keeps current stock separate from historical check quantities, including zero and unknown', () => {
        const balance = controlledColumns.find(
            (column) => column.key === 'balance',
        )!;
        const row = {
            on_hand: 12,
            unit: 'tablets',
            last_check_at: '2026-10-05T01:00:00Z',
            register_balance: 20,
        } as ControlledRegisterRow;
        const { rerender } = render(<>{balance.cell(row)}</>);
        expect(screen.getByText('12 tablets on hand')).toBeVisible();
        expect(screen.getByText('Last check: 20 tablets')).toBeVisible();
        rerender(<>{balance.cell({ ...row, register_balance: null })}</>);
        expect(screen.getByText('Last check: Balance unknown')).toBeVisible();
        rerender(<>{balance.cell({ ...row, last_check_at: null })}</>);
        expect(screen.getByText('Last check: Not recorded')).toBeVisible();
        rerender(
            <>{balance.cell({ ...row, on_hand: null, register_balance: 0 })}</>,
        );
        expect(screen.getByText('On hand unknown')).toBeVisible();
        expect(screen.getByText('Last check: 0 tablets')).toBeVisible();
    });

    it('keeps each delivery timestamp and unknown quantities available in compact order details', () => {
        const order: PharmacyOrderRow = {
            id: 7,
            medication_id: 12,
            client_name: 'Preview Person',
            medication_name: 'Preview medicine',
            pharmacy_name: 'Preview pharmacy',
            controlled: false,
            order_type: 'routine',
            status: 'submitted',
            quantity_ordered: 20,
            quantity_received: null,
            ordered_at: '2026-10-04T12:00:00Z',
            submitted_at: '2026-10-04T13:00:00Z',
            confirmed_at: null,
            dispensed_at: null,
            delivered_at: null,
            batch_number: null,
            batch_expiry: '2026-12-31',
        };
        render(
            <PharmacyOrderDetail
                order={order}
                onClose={vi.fn()}
                onAdvance={vi.fn()}
                busy={false}
            />,
        );
        expect(screen.getByRole('dialog')).toHaveAccessibleName(
            /Pharmacy order · Preview medicine/,
        );
        expect(
            screen.getByText('Preview Person · Preview pharmacy'),
        ).toBeVisible();
        expect(screen.getByText('31 Dec 2026')).toBeVisible();
        expect(
            screen.getByText('Quantity received').nextElementSibling,
        ).toHaveTextContent('Not recorded');
        expect(
            screen.getByRole('button', { name: 'Mark confirmed' }),
        ).toBeEnabled();
        expect(screen.getAllByRole('listitem')).toHaveLength(5);
    });
});
