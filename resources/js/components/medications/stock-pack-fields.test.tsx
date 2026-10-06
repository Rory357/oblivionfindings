import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it } from 'vitest';
import {
    StockPackFields,
    validateStockPackLines,
    type PackStockContext,
    type StockPackLine,
} from './stock-pack-fields';

const stock: PackStockContext = {
    stock_id: 1,
    unit: 'tablets',
    lots_started: true,
    lots: [
        {
            id: 8,
            batch_number: 'A',
            expiry_date: '2025-01-31',
            state: 'quarantined',
            quantity_remaining: '4',
            revision: 3,
        },
        {
            id: 9,
            batch_number: 'B',
            expiry_date: '2027-03-31',
            state: 'open',
            quantity_remaining: '0',
            revision: 2,
        },
    ],
};
describe('physical pack evidence', () => {
    it('starts every physical count blank, including expired, quarantined and empty packs', () => {
        function Count() {
            const [value, setValue] = useState<StockPackLine[]>([]);
            return (
                <>
                    <StockPackFields
                        stock={stock}
                        value={value}
                        onChange={setValue}
                        count
                    />
                    <output>
                        {validateStockPackLines(stock, value, true) ??
                            'Complete'}
                    </output>
                </>
            );
        }
        render(<Count />);
        const fields = screen.getAllByRole('spinbutton');
        expect(fields).toHaveLength(2);
        fields.forEach((field) => expect(field).toHaveValue(null));
        fireEvent.change(fields[0], { target: { value: '4' } });
        expect(screen.queryByText('Complete')).not.toBeInTheDocument();
        fireEvent.change(fields[1], { target: { value: '0' } });
        expect(screen.getByText('Complete')).toBeInTheDocument();
    });
    it('rejects changed, foreign and duplicate pack evidence before review', () => {
        expect(
            validateStockPackLines(stock, [
                { lot_id: 8, revision: 2, quantity: '1' },
            ]),
        ).toMatch(/changed/);
        expect(
            validateStockPackLines(stock, [
                { lot_id: 88, revision: 3, quantity: '1' },
            ]),
        ).toMatch(/changed/);
        expect(
            validateStockPackLines(stock, [
                { lot_id: 8, revision: 3, quantity: '1' },
                { lot_id: 8, revision: 3, quantity: '1' },
            ]),
        ).toMatch(/only once/);
        expect(
            validateStockPackLines(stock, [
                { lot_id: 8, revision: 3, quantity: '0' },
            ]),
        ).not.toBeNull();
        expect(
            validateStockPackLines(
                stock,
                [{ lot_id: 8, revision: 3, quantity: '0', used_away: '4' }],
                false,
                true,
            ),
        ).toBeNull();
    });
});
