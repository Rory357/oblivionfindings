import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { TicketCloseDialog } from '../ticket-close-dialog';

const mocks = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    router: { post: mocks.post },
}));

vi.mock('sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

describe('TicketCloseDialog', () => {
    beforeEach(() => mocks.post.mockReset());

    it('requires a reason and sends the single-ticket closure evidence', () => {
        render(
            <TicketCloseDialog
                open
                onOpenChange={vi.fn()}
                scope="single"
                ticketIds={[42]}
                ticketReference="IT-000042"
            />,
        );

        expect(
            screen.getByRole('heading', { name: /Close IT-000042/ }),
        ).toBeVisible();
        const close = screen.getByRole('button', { name: 'Close ticket' });
        expect(close).toBeDisabled();

        fireEvent.change(screen.getByLabelText('Reason for closing'), {
            target: { value: 'Requester confirmed the service is restored.' },
        });
        fireEvent.click(close);

        expect(mocks.post).toHaveBeenCalledWith(
            '/it/tickets/42/close',
            { reason: 'Requester confirmed the service is restored.' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('explains bulk consequences and sends the same reason for every selected ticket', () => {
        render(
            <TicketCloseDialog
                open
                onOpenChange={vi.fn()}
                scope="bulk"
                ticketIds={[7, 9]}
            />,
        );

        expect(
            screen.getByText(/reason appears on every ticket timeline/i),
        ).toBeVisible();
        fireEvent.change(screen.getByLabelText('Reason for closing'), {
            target: { value: '  Duplicate work confirmed elsewhere.  ' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Close selected tickets' }),
        );

        expect(mocks.post).toHaveBeenCalledWith(
            '/it/tickets/bulk',
            {
                ids: [7, 9],
                action: 'close',
                reason: 'Duplicate work confirmed elsewhere.',
            },
            expect.objectContaining({
                preserveScroll: true,
                preserveState: true,
            }),
        );
    });
});
