import { clearItTicketDraftMemory } from '@/hooks/use-it-ticket-draft-memory';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { TicketRoutingDialog } from '../ticket-routing-dialog';
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { user: { id: 11 } } } }),
    router: { reload: vi.fn(), visit: vi.fn(), on: () => () => {} },
}));
vi.mock('sonner', () => ({ toast: { success: vi.fn() } }));
beforeEach(() => clearItTicketDraftMemory());
afterEach(() => vi.restoreAllMocks());
const empty = {
    id: 42,
    lock_version: 7,
    assignee: null,
    routing: { queue: null, owner: null, team: null },
};
describe('routing choices', () => {
    it('shows missing configuration truthfully and allows an untouched form to close', () => {
        const close = vi.fn();
        const request = vi.spyOn(axios, 'request');
        render(
            <TicketRoutingDialog
                ticket={empty}
                queues={[]}
                agents={[]}
                onClose={close}
            />,
        );
        expect(
            screen.getByText(/No eligible active queue is configured/),
        ).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Save routing' }),
        ).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(close).toHaveBeenCalledOnce();
        expect(request).not.toHaveBeenCalled();
    });
    it('releases manual routing with an explicit reason without submitting fake queue or owner IDs', async () => {
        const request = vi.spyOn(axios, 'request').mockResolvedValueOnce({
            status: 200,
            data: {
                status: 'committed',
                data: { id: 42, viewer_user_id: 11, lock_version: 8 },
            },
        });
        const close = vi.fn();
        render(
            <TicketRoutingDialog
                ticket={empty}
                queues={[]}
                agents={[]}
                onClose={close}
            />,
        );
        fireEvent.click(
            screen.getByRole('combobox', { name: 'Routing choice' }),
        );
        fireEvent.click(
            screen.getByRole('option', { name: 'Use automatic routing' }),
        );
        fireEvent.change(screen.getByLabelText('Reason for routing change'), {
            target: { value: 'Use the approved current fallback rules.' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save routing' }));
        await waitFor(() => expect(close).toHaveBeenCalledOnce());
        expect(request.mock.calls[0][0].data).toEqual({
            actor_user_id: 11,
            expected_version: 7,
            release_routing_override: true,
            routing_reason: 'Use the approved current fallback rules.',
        });
    });
});
