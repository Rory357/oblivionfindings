import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ReportErrorModal } from './report-error-modal';

vi.mock('@inertiajs/react', () => ({ router: { post: vi.fn() } }));

afterEach(async () => {
    cleanup();
    await new Promise((resolve) => window.setTimeout(resolve, 0));
});

describe('medication error draft recovery', () => {
    it('keeps the account after cancel and requires an explicit discard before closing', async () => {
        const onClose = vi.fn();
        render(<ReportErrorModal open onClose={onClose} clients={[]} />);
        const report = screen.getByRole('dialog', {
            name: 'Report a medication error',
        });
        fireEvent.change(
            within(report).getByRole('textbox', { name: 'What happened?' }),
            {
                target: {
                    value: 'The dose was found on the floor before it reached the person.',
                },
            },
        );
        fireEvent.click(within(report).getByRole('button', { name: 'Cancel' }));
        const confirmation = await screen.findByRole('dialog', {
            name: 'Discard your changes?',
        });
        expect(onClose).not.toHaveBeenCalled();
        fireEvent.click(
            within(confirmation).getByRole('button', { name: 'Keep editing' }),
        );
        await waitFor(() =>
            expect(
                screen.queryByRole('dialog', { name: 'Discard your changes?' }),
            ).not.toBeInTheDocument(),
        );
        expect(
            within(report).getByRole('textbox', { name: 'What happened?' }),
        ).toHaveValue(
            'The dose was found on the floor before it reached the person.',
        );
        fireEvent.click(within(report).getByRole('button', { name: 'Close' }));
        fireEvent.click(
            await screen.findByRole('button', { name: 'Discard changes' }),
        );
        expect(onClose).toHaveBeenCalledOnce();
    });

    it('allows an untouched report to close without a discard prompt', () => {
        const onClose = vi.fn();
        render(<ReportErrorModal open onClose={onClose} clients={[]} />);
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(onClose).toHaveBeenCalledOnce();
        expect(
            screen.queryByRole('dialog', { name: 'Discard your changes?' }),
        ).not.toBeInTheDocument();
    });
});
