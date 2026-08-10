import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { TicketReopenDialog } from '../ticket-reopen-dialog';

const mocks = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    router: { post: mocks.post },
}));

vi.mock('sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

describe('TicketReopenDialog', () => {
    beforeEach(() => mocks.post.mockReset());

    it('requires a requester explanation and submits trimmed recovery evidence', () => {
        render(
            <TicketReopenDialog
                open
                onOpenChange={vi.fn()}
                ticketId={42}
                ticketReference="IT-000042"
                audience="requester"
            />,
        );

        expect(
            screen.getByText(/explanation appears in the conversation/i),
        ).toBeVisible();
        const reopen = screen.getByRole('button', { name: 'Reopen ticket' });
        expect(reopen).toBeDisabled();

        fireEvent.change(screen.getByLabelText('What still needs attention?'), {
            target: { value: '  The connection dropped again.  ' },
        });
        fireEvent.click(reopen);

        expect(mocks.post).toHaveBeenCalledWith(
            '/it/tickets/42/reopen',
            { reason: 'The connection dropped again.' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('explains that technician reasons remain internal', () => {
        render(
            <TicketReopenDialog
                open
                onOpenChange={vi.fn()}
                ticketId={7}
                ticketReference="IT-000007"
                audience="agent"
            />,
        );

        expect(screen.getByText(/recorded as an internal note/i)).toBeVisible();
        expect(screen.getByLabelText('Reason for reopening')).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Keep settled' }),
        ).toHaveClass('min-h-11');
    });
});
