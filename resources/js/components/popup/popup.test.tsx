import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { UserPlus } from 'lucide-react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { POPUP_WIDTH, PopupConfirm, PopupForm, PopupSelectionBar } from './popup';

afterEach(cleanup);

function Form({
    onClose = vi.fn(),
    onSubmit = vi.fn(),
    dirty = false,
}: {
    onClose?: () => void;
    onSubmit?: () => void;
    dirty?: boolean;
}) {
    return (
        <PopupForm
            open
            onClose={onClose}
            icon={UserPlus}
            title="Assign alert"
            description="Choose who owns the response."
            context={{ title: 'CR-2026-00418 · Outside the safe zone', tag: 'Aurora House' }}
            submitLabel="Assign alert"
            onSubmit={onSubmit}
            dirty={dirty}
        >
            <label>
                Reason
                <input name="reason" />
            </label>
        </PopupForm>
    );
}

describe('PopupForm', () => {
    it('uses an approved width and the kit header, context and footer', () => {
        render(<Form />);
        const dialog = screen.getByRole('dialog', { name: 'Assign alert' });
        expect(dialog).toHaveAttribute('data-popup-size', 'sm');
        expect(POPUP_WIDTH).toEqual({ sm: 480, md: 720, lg: 900, xl: 1100 });
        expect(screen.getByText('Choose who owns the response.')).toBeInTheDocument();
        expect(screen.getByText('CR-2026-00418 · Outside the safe zone')).toBeInTheDocument();
        expect(screen.getByText('Aurora House')).toBeInTheDocument();

        const buttons = screen.getAllByRole('button').map((b) => b.textContent);
        // Cancel comes before the main button, which names the action.
        expect(buttons.indexOf('Cancel')).toBeLessThan(buttons.indexOf('Assign alert'));
    });

    it('submits on Enter and never as a bare "Submit"', () => {
        const onSubmit = vi.fn();
        render(<Form onSubmit={onSubmit} />);
        fireEvent.submit(screen.getByRole('textbox').closest('form')!);
        expect(onSubmit).toHaveBeenCalledTimes(1);
        expect(screen.queryByRole('button', { name: 'Submit' })).not.toBeInTheDocument();
    });

    it('closes straight away when nothing was typed', () => {
        const onClose = vi.fn();
        render(<Form onClose={onClose} />);
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('asks the one "Discard this draft?" question before losing typing', () => {
        const onClose = vi.fn();
        render(<Form onClose={onClose} dirty />);

        fireEvent.click(screen.getByRole('button', { name: 'Close' }));
        expect(onClose).not.toHaveBeenCalled();
        expect(
            screen.getByRole('alertdialog', { name: 'Discard this draft?' }),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(screen.getByRole('dialog', { name: 'Assign alert' })).toBeInTheDocument();
        expect(onClose).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(screen.getByRole('button', { name: 'Discard draft' }));
        expect(onClose).toHaveBeenCalledTimes(1);
    });
});

describe('PopupConfirm', () => {
    it('is red only for the destructive variant', () => {
        const { rerender } = render(
            <PopupConfirm
                open
                onClose={vi.fn()}
                onConfirm={vi.fn()}
                title="Resolve this alert?"
                confirmLabel="Resolve alert"
            />,
        );
        expect(screen.getByRole('button', { name: 'Resolve alert' }).className).not.toMatch(
            /bg-destructive/,
        );

        rerender(
            <PopupConfirm
                open
                onClose={vi.fn()}
                onConfirm={vi.fn()}
                variant="destructive"
                title="Delete signal rule?"
                confirmLabel="Delete signal rule"
            />,
        );
        expect(
            screen.getByRole('button', { name: 'Delete signal rule' }).className,
        ).toMatch(/bg-destructive/);
    });

    it('shows one OK-style button for an alert', () => {
        render(
            <PopupConfirm
                open
                onClose={vi.fn()}
                onConfirm={vi.fn()}
                variant="alert"
                title="Couldn't send"
                confirmLabel="Got it"
            />,
        );
        expect(screen.queryByRole('button', { name: 'Cancel' })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Got it' })).toBeInTheDocument();
    });

    it('keeps both buttons disabled while the request is in flight', () => {
        render(
            <PopupConfirm
                open
                onClose={vi.fn()}
                onConfirm={vi.fn()}
                title="Escalate to level 2?"
                confirmLabel="Escalate to level 2"
                processing
            />,
        );
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        expect(screen.getByRole('button', { name: /Working/ })).toBeDisabled();
    });
});

describe('PopupSelectionBar', () => {
    function Harness() {
        const [count, setCount] = useState(3);
        return (
            <PopupSelectionBar count={count} onClear={() => setCount(0)}>
                <button type="button">Assign</button>
            </PopupSelectionBar>
        );
    }

    it('names the count and clears', () => {
        render(<Harness />);
        expect(screen.getByText('3 alerts selected')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Clear selection' }));
        expect(screen.queryByText(/selected/)).not.toBeInTheDocument();
    });
});
