import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    NewPharmacyOrderDialog,
    ReceiveStockDialog,
    StockCountDialog,
    type StockRow,
} from './_stock-dialogs';

afterEach(cleanup);

describe('pharmacy order person and medicine selection', () => {
    it('keeps a receipt draft when closing is cancelled and guards the first-step Cancel too', async () => {
        const onClose = vi.fn();
        render(
            <ReceiveStockDialog
                medications={[
                    {
                        id: 11,
                        name: 'Synthetic medicine',
                        client_id: 1,
                        controlled: false,
                    },
                ]}
                defaultMedId={11}
                onClose={onClose}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.change(screen.getByRole('spinbutton'), {
            target: { value: '12' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Close' }));
        expect(
            await screen.findByRole('dialog', {
                name: 'Discard your changes?',
            }),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(screen.getByRole('spinbutton')).toHaveValue(12);
        expect(onClose).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Back' }));
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(
            await screen.findByRole('dialog', {
                name: 'Discard your changes?',
            }),
        ).toBeInTheDocument();
        expect(onClose).not.toHaveBeenCalled();
    });
    it('retains the selected person, limits their medicines and allows the next step', async () => {
        render(
            <NewPharmacyOrderDialog
                clients={[
                    { id: 1, first_name: 'Ada', last_name: 'Synthetic' },
                    { id: 2, first_name: 'Ben', last_name: 'Synthetic' },
                ]}
                medications={[
                    { id: 11, name: 'Ada medicine', client_id: 1 },
                    { id: 22, name: 'Ben medicine', client_id: 2 },
                ]}
                stockItems={[]}
                onClose={vi.fn()}
            />,
        );
        fireEvent.keyDown(
            screen.getByRole('combobox', { name: 'Select client…' }),
            { key: 'ArrowDown' },
        );
        fireEvent.click(
            await screen.findByRole('option', { name: 'Ada Synthetic' }),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('combobox', { name: 'Select client…' }),
            ).toHaveTextContent('Ada Synthetic'),
        );
        fireEvent.keyDown(
            screen.getByRole('combobox', { name: 'Select medication…' }),
            { key: 'ArrowDown' },
        );
        expect(
            screen.queryByRole('option', { name: 'Ben medicine' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            await screen.findByRole('option', { name: 'Ada medicine' }),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Continue' }),
            ).toBeEnabled(),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(
            screen.getByText('Step 2 of 4 ·', { exact: false }),
        ).toBeInTheDocument();
    });
});

describe('stock count evidence', () => {
    it('blocks a controlled check when the register balance is unknown', () => {
        render(
            <StockCountDialog
                medications={[
                    {
                        id: 77,
                        client_id: 1,
                        name: 'Synthetic controlled medicine',
                        controlled: true,
                    },
                ]}
                stockItems={[
                    {
                        medication_id: 77,
                        on_hand: null,
                        unit: 'tablets',
                        controlled: true,
                    } as StockRow,
                ]}
                witnesses={[]}
                defaultMedId={77}
                onClose={vi.fn()}
            />,
        );
        expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
        expect(screen.getByText('Unknown')).toBeVisible();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'The register balance is unknown',
        );
        expect(screen.queryByText('0 tablets')).not.toBeInTheDocument();
    });
    it('waits for off-page stock before allowing an order to continue', async () => {
        let resolve: (row: StockRow | null) => void = () => {};
        const load = vi.fn(
            () =>
                new Promise<StockRow | null>((done) => {
                    resolve = done;
                }),
        );
        render(
            <NewPharmacyOrderDialog
                clients={[
                    { id: 1, first_name: 'Preview', last_name: 'Person' },
                ]}
                medications={[
                    { id: 77, client_id: 1, name: 'Preview medicine' },
                ]}
                stockItems={[]}
                defaultClientId={1}
                defaultMedId={77}
                loadStockContext={load}
                onClose={vi.fn()}
            />,
        );
        expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
        resolve({
            medication_id: 77,
            on_hand: 13,
            reorder_level: 8,
            reorder_quantity: 21,
            unit: 'tablets',
        } as StockRow);
        await waitFor(() =>
            expect(screen.getByText('13 tablets')).toBeVisible(),
        );
        expect(screen.getByRole('button', { name: 'Continue' })).toBeEnabled();
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(screen.getByRole('spinbutton')).toHaveValue(21);
    });
});
