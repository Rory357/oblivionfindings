import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { ResolveAlertWizard } from './index';

describe('ResolveAlertWizard', () => {
    it('requires resolution notes, supports review navigation, and cancels safely', () => {
        const onNotesChange = vi.fn();
        const onClose = vi.fn();
        const onSubmit = vi.fn();

        const { rerender } = render(
            <ResolveAlertWizard
                open
                notes=""
                onNotesChange={onNotesChange}
                onClose={onClose}
                onSubmit={onSubmit}
            />,
        );

        expect(
            screen.getByRole('dialog', { name: 'Resolve alert' }),
        ).toHaveAccessibleDescription(
            'Add resolution notes and review them before closing the active alert workflow.',
        );
        expect(screen.getByLabelText('Resolution notes')).toBeVisible();
        expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();

        fireEvent.change(screen.getByLabelText('Resolution notes'), {
            target: { value: 'Vehicle recovered and tracker reset.' },
        });
        expect(onNotesChange).toHaveBeenCalledWith(
            'Vehicle recovered and tracker reset.',
        );

        rerender(
            <ResolveAlertWizard
                open
                notes="Vehicle recovered and tracker reset."
                onNotesChange={onNotesChange}
                onClose={onClose}
                onSubmit={onSubmit}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(screen.getByText('Vehicle recovered and tracker reset.')).toBeVisible();
        expect(screen.getByRole('button', { name: 'Resolve alert' })).toBeVisible();

        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(onClose).toHaveBeenCalledTimes(1);
        expect(onSubmit).not.toHaveBeenCalled();
    });
});
