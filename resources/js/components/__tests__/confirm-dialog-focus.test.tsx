import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { useRef, useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';

function Harness({
    onConfirm,
    onCloseFocus,
}: {
    onConfirm: () => void;
    onCloseFocus?: () => void;
}) {
    const [open, setOpen] = useState(false);
    const opener = useRef<HTMLButtonElement>(null);
    return (
        <>
            <Button ref={opener} type="button" onClick={() => setOpen(true)}>
                Open confirmation
            </Button>
            <ConfirmDialog
                open={open}
                onClose={() => setOpen(false)}
                onConfirm={onConfirm}
                title="Discard this proposal?"
                description="The unsent proposal will be removed."
                confirmText="Discard proposal"
                onCloseAutoFocus={
                    onCloseFocus
                        ? (event) => {
                              event.preventDefault();
                              onCloseFocus();
                              opener.current?.focus();
                          }
                        : undefined
                }
            />
        </>
    );
}

afterEach(cleanup);

describe('ConfirmDialog optional close autofocus', () => {
    it.each(['Cancel', 'Discard proposal'])(
        'passes the opt-in callback after %s while preserving confirmation semantics',
        async (action) => {
            const onConfirm = vi.fn();
            const onCloseFocus = vi.fn();
            render(
                <Harness onConfirm={onConfirm} onCloseFocus={onCloseFocus} />,
            );
            const opener = screen.getByRole('button', {
                name: 'Open confirmation',
            });
            opener.focus();
            fireEvent.click(opener);
            const dialog = screen.getByRole('alertdialog', {
                name: 'Discard this proposal?',
            });
            fireEvent.click(
                within(dialog).getByRole('button', { name: action }),
            );
            await waitFor(() =>
                expect(
                    screen.queryByRole('alertdialog'),
                ).not.toBeInTheDocument(),
            );
            await waitFor(() => expect(onCloseFocus).toHaveBeenCalledTimes(1));
            expect(opener).toHaveFocus();
            expect(onConfirm).toHaveBeenCalledTimes(
                action === 'Cancel' ? 0 : 1,
            );
        },
    );

    it('preserves default destructive confirmation and cancellation when the new callback is omitted', async () => {
        const onConfirm = vi.fn();
        render(<Harness onConfirm={onConfirm} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Open confirmation' }),
        );
        const dialog = screen.getByRole('alertdialog', {
            name: 'Discard this proposal?',
        });
        expect(
            within(dialog).getByRole('button', { name: 'Discard proposal' }),
        ).toHaveClass('bg-destructive');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
        expect(onConfirm).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Open confirmation' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard proposal' }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
        expect(onConfirm).toHaveBeenCalledTimes(1);
    });
});
