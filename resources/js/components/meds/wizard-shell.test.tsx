import { Button } from '@/components/ui/button';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { Pill } from 'lucide-react';
import type { ComponentProps } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { MedsWizardDialog } from './wizard-shell';

afterEach(async () => {
    cleanup();
    await new Promise((resolve) => window.setTimeout(resolve, 0));
});
const steps = [
    {
        key: 'medicine',
        label: 'Medicine',
        blurb: 'Choose the order',
        icon: Pill,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check before saving',
        icon: Pill,
    },
];
function example(
    overrides: Partial<ComponentProps<typeof MedsWizardDialog>> = {},
) {
    const onClose = vi.fn(),
        onSave = vi.fn(),
        onStepClick = vi.fn();
    const props: ComponentProps<typeof MedsWizardDialog> = {
        open: true,
        title: 'Medication change',
        description: 'Check this medication change.',
        railIcon: Pill,
        railTitle: 'Medication',
        railSubtitle: 'Person record',
        steps,
        stepIndex: 0,
        onClose,
        onStepClick,
        footer: (
            <>
                <Button onClick={onClose}>Cancel</Button>
                <Button onClick={onSave}>Save change</Button>
            </>
        ),
        children: (
            <label>
                Reason
                <input aria-label="Reason" />
            </label>
        ),
        ...overrides,
    };
    return { props, onClose, onSave, onStepClick };
}
describe('medication popup contracts', () => {
    it('guards Cancel nested inside a form footer component', async () => {
        const setup = example({
            formState: { isDirty: true, processing: false },
        });
        function Footer({ onCancel }: { onCancel: () => void }) {
            return (
                <Button type="button" onClick={onCancel}>
                    Cancel
                </Button>
            );
        }
        render(
            <MedsWizardDialog
                {...setup.props}
                footer={
                    <form>
                        <Footer onCancel={setup.onClose} />
                    </form>
                }
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(
            await screen.findByRole('dialog', {
                name: 'Discard your changes?',
            }),
        ).toBeInTheDocument();
        expect(setup.onClose).not.toHaveBeenCalled();
    });
    it('keeps step navigation and submit callbacks in the shared wizard', () => {
        const setup = example();
        render(<MedsWizardDialog {...setup.props} />);
        fireEvent.click(
            screen.getByRole('button', { name: /Review.*Check before saving/ }),
        );
        expect(setup.onStepClick).toHaveBeenCalledWith(1);
        fireEvent.click(screen.getByRole('button', { name: 'Save change' }));
        expect(setup.onSave).toHaveBeenCalledOnce();
        expect(setup.onClose).not.toHaveBeenCalled();
        expect(
            screen
                .getByRole('dialog')
                .querySelector('[data-wizard-region="body"]'),
        ).toHaveClass('overflow-y-auto');
    });
    it.each([false, true])(
        'guards Cancel and keeps the form when dirty (simple: %s)',
        async (simple) => {
            const setup = example({
                steps: simple ? steps.slice(0, 1) : steps,
                formState: { isDirty: true, processing: false },
            });
            render(<MedsWizardDialog {...setup.props} />);
            fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
            const confirmation = await screen.findByRole('dialog', {
                name: 'Discard your changes?',
            });
            expect(setup.onClose).not.toHaveBeenCalled();
            fireEvent.click(
                within(confirmation).getByRole('button', {
                    name: 'Keep editing',
                }),
            );
            expect(
                screen.getByRole('textbox', { name: 'Reason' }),
            ).toBeInTheDocument();
            fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
            fireEvent.click(
                await screen.findByRole('button', { name: 'Discard changes' }),
            );
            expect(setup.onClose).toHaveBeenCalledOnce();
            await waitFor(() =>
                expect(
                    screen.queryByRole('dialog', {
                        name: 'Discard your changes?',
                    }),
                ).not.toBeInTheDocument(),
            );
        },
    );
    it('blocks closing during a request and exposes returned errors without losing the form', async () => {
        const setup = example({
            formState: { isDirty: true, processing: true },
        });
        const view = render(<MedsWizardDialog {...setup.props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Close' }));
        expect(setup.onClose).not.toHaveBeenCalled();
        view.rerender(
            <MedsWizardDialog
                {...setup.props}
                formState={{
                    isDirty: true,
                    processing: false,
                    errors: {
                        correction:
                            'A different authorised person must approve this correction.',
                    },
                }}
            />,
        );
        const error = await screen.findByRole('alert');
        expect(error).toHaveTextContent(
            'A different authorised person must approve this correction.',
        );
        await waitFor(() => expect(error).toHaveFocus());
        expect(
            screen.getByRole('textbox', { name: 'Reason' }),
        ).toBeInTheDocument();
    });
});
