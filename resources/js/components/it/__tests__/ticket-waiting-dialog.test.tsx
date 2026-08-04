import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    requesterWaitingCopy,
    TicketWaitingDialog,
    waitingStatusLabel,
} from '../ticket-waiting-dialog';

const mocks = vi.hoisted(() => ({ patch: vi.fn(), post: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    router: { patch: mocks.patch, post: mocks.post },
}));

vi.mock('sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

describe('TicketWaitingDialog', () => {
    beforeEach(() => {
        mocks.patch.mockReset();
        mocks.post.mockReset();
    });

    it('requires operational evidence and records a single ticket vendor wait', () => {
        render(
            <TicketWaitingDialog
                open
                onOpenChange={vi.fn()}
                scope="single"
                ticketIds={[42]}
                ticketReference="IT-000042"
            />,
        );

        const submit = screen.getByRole('button', { name: 'Set waiting' });
        expect(submit).toBeDisabled();

        fireEvent.click(
            screen.getByRole('combobox', {
                name: 'Who or what is IT waiting for?',
            }),
        );
        fireEvent.click(
            screen.getByRole('option', { name: 'Vendor or supplier' }),
        );
        fireEvent.change(screen.getByLabelText('Reason for waiting'), {
            target: { value: 'Supplier must confirm the replacement serial.' },
        });
        fireEvent.change(screen.getByLabelText(/Next action/), {
            target: { value: 'Review the response tomorrow morning.' },
        });
        fireEvent.click(submit);

        expect(mocks.patch).toHaveBeenCalledWith(
            '/it/tickets/42',
            {
                status: 'waiting',
                waiting_party: 'vendor',
                waiting_reason: 'Supplier must confirm the replacement serial.',
                next_action: 'Review the response tomorrow morning.',
            },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('records the same governed detail for every selected ticket', () => {
        render(
            <TicketWaitingDialog
                open
                onOpenChange={vi.fn()}
                scope="bulk"
                ticketIds={[7, 9]}
            />,
        );

        fireEvent.change(screen.getByLabelText('Reason for waiting'), {
            target: { value: 'Requester evidence is still required.' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Set 2 tickets waiting' }),
        );

        expect(mocks.post).toHaveBeenCalledWith(
            '/it/tickets/bulk',
            {
                ids: [7, 9],
                action: 'status',
                status: 'waiting',
                waiting_party: 'requester',
                waiting_reason: 'Requester evidence is still required.',
                next_action: null,
            },
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('uses accurate technician labels and privacy-safe requester copy', () => {
        expect(waitingStatusLabel('vendor')).toBe(
            'Waiting · Vendor or supplier',
        );
        expect(waitingStatusLabel('other', true)).toBe('Waiting on IT');
        expect(waitingStatusLabel('requester', true)).toBe('Waiting on you');
        expect(requesterWaitingCopy('requester')).toMatch(
            /reply in the conversation/i,
        );
        expect(requesterWaitingCopy('other')).not.toContain('supplier');
    });
});
