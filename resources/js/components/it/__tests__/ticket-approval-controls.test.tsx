import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { TicketApprovalControls } from '../ticket-approval-controls';

const mocks = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    router: { post: mocks.post },
}));

describe('ticket approval controls', () => {
    it('opens a governed request dialog and submits an optional reason', () => {
        render(
            <TicketApprovalControls
                ticket={{ id: 42, reference: 'IT-000042', approval: null }}
                canRequest
                canDecide={false}
                formatDateTime={() => '3 Aug 2026, 10:00 am'}
            />,
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'Request approval' }),
        );

        expect(
            screen.getByRole('heading', { name: 'Request manager approval' }),
        ).toBeVisible();
        expect(
            screen.getByText(/recorded decision before settlement/i),
        ).toBeVisible();
        const reason = screen.getByRole('textbox', {
            name: /Why is approval needed/i,
        });
        fireEvent.change(reason, {
            target: { value: 'Licence owner sign-off' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Request approval' }),
        );

        expect(mocks.post).toHaveBeenCalledWith(
            '/it/tickets/42/approvals',
            { reason: 'Licence owner sign-off' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('requires an actionable reason before rejecting a pending request', () => {
        render(
            <TicketApprovalControls
                ticket={{
                    id: 42,
                    reference: 'IT-000042',
                    approval: {
                        id: 9,
                        status: 'pending',
                        requested_by_name: 'Taylor Agent',
                        approver_name: null,
                        reason: 'New privileged account',
                        requested_at: '2026-08-03T00:00:00Z',
                        decided_at: null,
                    },
                }}
                canRequest={false}
                canDecide
                formatDateTime={() => '3 Aug 2026, 12:00 pm'}
            />,
        );

        expect(screen.getByText(/New privileged account/)).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Reject' }));

        expect(
            screen.getByRole('heading', { name: 'Reject this request' }),
        ).toBeVisible();
        expect(screen.getByText(/what must change/i)).toBeVisible();
        expect(
            screen.getByRole('textbox', { name: 'Reason for rejection' }),
        ).toBeRequired();
        expect(
            screen.getByRole('button', { name: 'Reject request' }),
        ).toHaveClass('min-h-11');
    });
});
