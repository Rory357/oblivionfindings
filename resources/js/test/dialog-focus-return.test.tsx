import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it } from 'vitest';

import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';

function OutcomeDialog() {
    const [open, setOpen] = useState(false);
    const [error, setError] = useState<string | null>(null);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <button type="button">Desktop search</button>
            </DialogTrigger>
            <DialogTrigger asChild>
                <button type="button" style={{ display: 'none' }}>
                    Mobile search
                </button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Search</DialogTitle>
                <DialogDescription>Find a module.</DialogDescription>
                <button
                    type="button"
                    onClick={() => setError('Search failed. Try again.')}
                >
                    Retry failed action
                </button>
                {error ? <p role="alert">{error}</p> : null}
                <DialogClose asChild>
                    <button type="button">Cancel search</button>
                </DialogClose>
                <button type="button" onClick={() => setOpen(false)}>
                    Complete search
                </button>
            </DialogContent>
        </Dialog>
    );
}

function ProgrammaticDialog() {
    const [open, setOpen] = useState(false);

    return (
        <>
            <button type="button" onClick={() => setOpen(true)}>
                Open from shortcut
            </button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogTitle>Shortcut dialog</DialogTitle>
                    <DialogDescription>
                        Opened without a DialogTrigger.
                    </DialogDescription>
                    <DialogClose asChild>
                        <button type="button">Close shortcut dialog</button>
                    </DialogClose>
                </DialogContent>
            </Dialog>
        </>
    );
}

function NestedDialogs() {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <button type="button">Open outer</button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Outer dialog</DialogTitle>
                <DialogDescription>Outer workflow.</DialogDescription>
                <Dialog>
                    <DialogTrigger asChild>
                        <button type="button">Open inner</button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogTitle>Inner dialog</DialogTitle>
                        <DialogDescription>Nested workflow.</DialogDescription>
                        <DialogClose asChild>
                            <button type="button">Close inner</button>
                        </DialogClose>
                    </DialogContent>
                </Dialog>
                <DialogClose asChild>
                    <button type="button">Close outer</button>
                </DialogClose>
            </DialogContent>
        </Dialog>
    );
}

function ResponsiveSheet() {
    return (
        <Sheet>
            <SheetTrigger asChild>
                <button type="button">Desktop menu</button>
            </SheetTrigger>
            <SheetTrigger asChild>
                <button type="button" style={{ display: 'none' }}>
                    Mobile menu
                </button>
            </SheetTrigger>
            <SheetContent>
                <SheetTitle>Navigation</SheetTitle>
                <SheetDescription>Choose a destination.</SheetDescription>
                <SheetClose asChild>
                    <button type="button">Close navigation</button>
                </SheetClose>
            </SheetContent>
        </Sheet>
    );
}

function StaleActivatorDialog() {
    const [open, setOpen] = useState(false);
    const [canReopen, setCanReopen] = useState(true);

    return (
        <nav aria-label="Request actions">
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogTrigger asChild>
                    <button type="button" disabled={!canReopen}>
                        Open request
                    </button>
                </DialogTrigger>
                <DialogContent>
                    <DialogTitle>Request</DialogTitle>
                    <DialogDescription>Complete the request.</DialogDescription>
                    <button
                        type="button"
                        onClick={() => {
                            setCanReopen(false);
                            setOpen(false);
                        }}
                    >
                        Complete and remove access
                    </button>
                </DialogContent>
            </Dialog>
        </nav>
    );
}

describe('shared overlay focus return', () => {
    it('returns cancel and successful completion to the activator that opened it', async () => {
        render(<OutcomeDialog />);

        const desktopTrigger = screen.getByRole('button', {
            name: 'Desktop search',
        });
        desktopTrigger.focus();
        fireEvent.click(desktopTrigger);

        const failedAction = screen.getByRole('button', {
            name: 'Retry failed action',
        });
        failedAction.focus();
        fireEvent.click(failedAction);
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Search failed. Try again.',
        );
        expect(screen.getByRole('dialog', { name: 'Search' })).toBeVisible();
        expect(failedAction).toHaveFocus();

        const cancel = screen.getByRole('button', { name: 'Cancel search' });
        cancel.focus();
        fireEvent.click(cancel);
        await waitFor(() => expect(desktopTrigger).toHaveFocus());

        fireEvent.click(desktopTrigger);
        const complete = screen.getByRole('button', {
            name: 'Complete search',
        });
        complete.focus();
        fireEvent.click(complete);
        await waitFor(() => expect(desktopTrigger).toHaveFocus());
    });

    it('restores the active element when a shortcut opens without a DialogTrigger', async () => {
        render(<ProgrammaticDialog />);

        const opener = screen.getByRole('button', {
            name: 'Open from shortcut',
        });
        opener.focus();
        fireEvent.click(opener);
        fireEvent.click(
            screen.getByRole('button', { name: 'Close shortcut dialog' }),
        );

        await waitFor(() => expect(opener).toHaveFocus());
    });

    it('keeps independent focus-return ownership for nested dialogs', async () => {
        render(<NestedDialogs />);

        const outerTrigger = screen.getByRole('button', { name: 'Open outer' });
        outerTrigger.focus();
        fireEvent.click(outerTrigger);

        const innerTrigger = screen.getByRole('button', { name: 'Open inner' });
        innerTrigger.focus();
        fireEvent.click(innerTrigger);
        fireEvent.click(screen.getByRole('button', { name: 'Close inner' }));

        await waitFor(() => expect(innerTrigger).toHaveFocus());
        expect(
            screen.getByRole('dialog', { name: 'Outer dialog' }),
        ).toBeVisible();

        fireEvent.click(screen.getByRole('button', { name: 'Close outer' }));
        await waitFor(() => expect(outerTrigger).toHaveFocus());
    });

    it('uses the same actual-activator contract for responsive sheets', async () => {
        render(<ResponsiveSheet />);

        const desktopTrigger = screen.getByRole('button', {
            name: 'Desktop menu',
        });
        desktopTrigger.focus();
        fireEvent.click(desktopTrigger);
        fireEvent.click(
            screen.getByRole('button', { name: 'Close navigation' }),
        );

        await waitFor(() => expect(desktopTrigger).toHaveFocus());
    });

    it('returns to a safe owner when the activator becomes unavailable', async () => {
        render(<StaleActivatorDialog />);

        const trigger = screen.getByRole('button', { name: 'Open request' });
        trigger.focus();
        fireEvent.click(trigger);
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Complete and remove access',
            }),
        );

        const owner = screen.getByRole('navigation', {
            name: 'Request actions',
        });
        await waitFor(() => expect(owner).toHaveFocus());
        expect(trigger).toBeDisabled();
    });
});
