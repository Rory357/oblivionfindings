import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { Circle } from 'lucide-react';
import type { ComponentProps } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { ConfirmDialog } from '@/components/confirm-dialog';

import { WizardShell, WizardSuccessPane } from './shell';

const twoSteps = [
    {
        key: 'details',
        label: 'Details',
        blurb: 'Add the core information',
        icon: Circle,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm before saving',
        icon: Circle,
    },
] as const;

function renderWizard(overrides: Partial<ComponentProps<typeof WizardShell>> = {}) {
    const onClose = vi.fn();

    const rendered = render(
        <>
            <button id="outside-action" type="button">
                Outside action
            </button>
            <WizardShell
                open
                onClose={onClose}
                title="Log fuel"
                description="Record a fuel purchase for a Fleet asset."
                railIcon={Circle}
                railTitle="Fuel log"
                railSub="Fleet & Assets"
                steps={twoSteps}
                stepIndex={0}
                onStepClick={vi.fn()}
                footerStart={<button type="button">Cancel</button>}
                footerEnd={<button type="button">Continue</button>}
                {...overrides}
            >
                <label htmlFor="receipt-number">Receipt number</label>
                <input id="receipt-number" />
            </WizardShell>
        </>,
    );

    return { onClose, ...rendered };
}

describe('WizardShell', () => {
    it('wires an accessible name and description to the complete shell regions', () => {
        const { container } = renderWizard();

        expect(
            screen.getByRole('dialog', { name: 'Log fuel' }),
        ).toHaveAccessibleDescription(
            'Record a fuel purchase for a Fleet asset.',
        );

        for (const region of ['rail', 'header', 'progress', 'body', 'footer']) {
            expect(
                container.ownerDocument.querySelector(
                    `[data-wizard-region="${region}"]`,
                ),
            ).not.toBeNull();
        }
    });

    it('keeps focus inside and closes on Escape', async () => {
        const { onClose } = renderWizard();
        const dialog = screen.getByRole('dialog', { name: 'Log fuel' });

        await waitFor(() =>
            expect(dialog).toContainElement(
                document.activeElement as HTMLElement | null,
            ),
        );

        document.getElementById('outside-action')?.focus();
        await waitFor(() =>
            expect(dialog).toContainElement(
                document.activeElement as HTMLElement | null,
            ),
        );

        fireEvent.keyDown(document, { key: 'Escape' });
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('uses coherent copy for a one-step workflow', () => {
        renderWizard({ steps: [twoSteps[0]] });

        expect(screen.getAllByText('Details')).toHaveLength(2);
        expect(screen.queryByText(/Step 1 of 1/)).toBeNull();
    });

    it('keeps the dialog name when success content replaces the form', () => {
        renderWizard({
            success: (
                <WizardSuccessPane
                    title="Fuel logged"
                    blurb="The purchase is now part of the asset record."
                    actions={<button type="button">Close</button>}
                />
            ),
        });

        expect(
            screen.getByRole('dialog', { name: 'Log fuel' }),
        ).toHaveAccessibleDescription(
            'Record a fuel purchase for a Fleet asset.',
        );
        expect(
            screen.getByRole('heading', { name: 'Fuel logged' }),
        ).toBeVisible();
    });
});

describe('ConfirmDialog', () => {
    it('wires title and description and focuses cancel for destructive actions', async () => {
        render(
            <ConfirmDialog
                open
                onClose={vi.fn()}
                onConfirm={vi.fn()}
                title="Delete geofence?"
                description="This removes the saved boundary and its active state."
                confirmText="Delete geofence"
            />,
        );

        const dialog = screen.getByRole('alertdialog', {
            name: 'Delete geofence?',
        });
        const cancel = screen.getByRole('button', { name: 'Cancel' });

        expect(dialog).toHaveAccessibleDescription(
            'This removes the saved boundary and its active state.',
        );
        await waitFor(() => expect(cancel).toHaveFocus());
        expect(
            screen.getByRole('button', { name: 'Delete geofence' }),
        ).toHaveClass('bg-destructive', 'text-destructive-foreground');
    });

    it('uses visible labels and semantic default action tokens', () => {
        render(
            <ConfirmDialog
                open
                onClose={vi.fn()}
                onConfirm={vi.fn()}
                title="Close trip?"
                description="The trip will move to the completed list."
                confirmText="Close trip"
                variant="default"
            />,
        );

        expect(screen.getByRole('button', { name: 'Cancel' })).toBeVisible();
        expect(screen.getByRole('button', { name: 'Close trip' })).toHaveClass(
            'bg-primary',
            'text-primary-foreground',
        );
    });
});
