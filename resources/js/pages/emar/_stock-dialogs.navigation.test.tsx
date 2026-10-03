import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { NewPharmacyOrderDialog } from './_stock-dialogs';

afterEach(cleanup);

describe('pharmacy order person and medicine selection', () => {
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
