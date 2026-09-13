import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { Circle } from 'lucide-react';
import { useState, type ComponentProps } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

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

afterEach(async () => {
    cleanup();
    // FocusScope defers its unmount callback. Settle it before the next
    // independent wizard mounts, so it cannot interrupt that focus scope.
    await new Promise((resolve) => window.setTimeout(resolve, 0));
});

function renderWizard(
    overrides: Partial<ComponentProps<typeof WizardShell>> = {},
) {
    const onClose = vi.fn();

    const rendered = render(
        <>
            <Button id="outside-action" type="button">
                Outside action
            </Button>
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
                footerStart={<Button type="button">Cancel</Button>}
                footerEnd={<Button type="button">Continue</Button>}
                {...overrides}
            >
                <label htmlFor="receipt-number">Receipt number</label>
                <Input id="receipt-number" />
            </WizardShell>
        </>,
    );

    return { onClose, ...rendered };
}

describe('WizardShell', () => {
    it('disables only explicitly unavailable steps while retaining normal navigation', () => {
        const onStepClick = vi.fn();
        renderWizard({
            steps: [{ ...twoSteps[0], disabled: true }, twoSteps[1]],
            onStepClick,
        });
        const unavailable = screen.getByRole('button', {
            name: /Details\s*Add the core information/,
        });
        expect(unavailable).toBeDisabled();
        fireEvent.click(unavailable);
        expect(onStepClick).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', {
                name: /Review\s*Confirm before saving/,
            }),
        );
        expect(onStepClick).toHaveBeenCalledWith(1);
    });

    it('lets a replacement workspace retain focus after the outgoing dialog unmounts', async () => {
        function ReplacementWorkflow() {
            const [handoff, setHandoff] = useState(false);
            return (
                <WizardShell
                    key={handoff ? 'handoff' : 'workspace'}
                    open
                    onClose={() => setHandoff(false)}
                    title={handoff ? 'IT handoff' : 'Alert workspace'}
                    description="Prepare technical work for the operational alert."
                    railIcon={Circle}
                    railTitle="Alert"
                    railSub="Control Room"
                    steps={twoSteps}
                    stepIndex={0}
                    onStepClick={vi.fn()}
                    onCloseAutoFocus={
                        handoff ? (event) => event.preventDefault() : undefined
                    }
                    onOpenAutoFocus={(event) => {
                        if (!handoff && event.target instanceof HTMLElement) {
                            const trigger =
                                event.target.querySelector<HTMLElement>(
                                    '[data-return-trigger]',
                                );
                            if (trigger) {
                                event.preventDefault();
                                trigger.focus();
                            }
                        }
                    }}
                >
                    {handoff ? (
                        <Button onClick={() => setHandoff(false)}>
                            Back to alert
                        </Button>
                    ) : (
                        <Button
                            data-return-trigger
                            onClick={() => setHandoff(true)}
                        >
                            Prepare IT handoff
                        </Button>
                    )}
                </WizardShell>
            );
        }
        render(<ReplacementWorkflow />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Prepare IT handoff' }),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('dialog', { name: 'IT handoff' }),
            ).toBeVisible(),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Back to alert' }));
        await new Promise((resolve) => window.setTimeout(resolve, 20));
        expect(
            screen.getByRole('button', { name: 'Prepare IT handoff' }),
        ).toHaveFocus();
    });

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
                    actions={<Button type="button">Close</Button>}
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
            'btn-soft-primary',
            'text-primary-foreground',
        );
    });
});
