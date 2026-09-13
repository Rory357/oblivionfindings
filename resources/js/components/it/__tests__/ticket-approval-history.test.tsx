import { TicketApprovalHistory } from '@/components/it/ticket-approval-history';
import { approvalRecord } from '@/test/it-approval-fixtures';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const transport = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock('axios', () => ({
    default: {
        ...transport,
        isAxiosError: (error: unknown) =>
            typeof error === 'object' && error !== null && 'response' in error,
    },
}));
const nonce = '11111111-1111-4111-8111-111111111111';
const response = () => ({
    status: 200,
    data: {
        viewer_user_id: 7,
        ticket_id: 42,
        review_nonce: nonce,
        lock_version: 4,
        target_approval_id: 1,
        history: {
            page: 2,
            per_page: 10,
            total: 12,
            next_page: null,
            records: [approvalRecord({ id: 2 }), approvalRecord({ id: 1 })],
        },
    },
});
const props = {
    actorId: 7,
    ticketId: 42,
    version: 4,
    total: 12,
    currentApprovalId: 12,
    onAccessLost: vi.fn(),
};
beforeEach(() => {
    transport.get.mockReset();
    vi.spyOn(crypto, 'randomUUID').mockReturnValue(nonce);
    window.history.replaceState(
        null,
        '',
        '/it/tickets/42?tab=approvals#approval-1',
    );
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    window.history.replaceState(null, '', '/');
});

it('opens and focuses the exact older approval through the existing history endpoint', async () => {
    transport.get.mockResolvedValueOnce(response());
    render(<TicketApprovalHistory {...props} />);
    await screen.findByText('Request #1');
    expect(
        screen.getByText('Page 2 · 12 recorded requests · No older requests'),
    ).toBeInTheDocument();
    expect(transport.get).toHaveBeenCalledTimes(1);
    expect(transport.get.mock.calls[0][1].params).toMatchObject({
        actor_user_id: 7,
        approval_id: 1,
    });
    await waitFor(() =>
        expect(document.getElementById('approval-1')).toHaveFocus(),
    );
});

it('keeps a failed linked lookup retryable without an automatic retry loop', async () => {
    transport.get.mockRejectedValueOnce({ response: { status: 500 } });
    render(<TicketApprovalHistory {...props} />);
    await screen.findByRole('button', { name: 'Retry history check' });
    expect(transport.get).toHaveBeenCalledTimes(1);
    transport.get.mockResolvedValueOnce(response());
    fireEvent.click(
        screen.getByRole('button', { name: 'Retry history check' }),
    );
    await screen.findByText('Request #1');
    expect(transport.get.mock.calls[1][1].params.approval_id).toBe(1);
});

it('does not load history for the current request', () => {
    window.history.replaceState(
        null,
        '',
        '/it/tickets/42?tab=approvals#approval-12',
    );
    render(<TicketApprovalHistory {...props} />);
    expect(transport.get).not.toHaveBeenCalled();
});

it.each(['last page', 'failure', 'cancel', 'focus moved'] as const)(
    'preserves keyboard ownership through history loading: %s',
    async (outcome) => {
        window.history.replaceState(null, '', '/it/tickets/42?tab=approvals');
        const first = response();
        const firstData = {
            ...first.data,
            target_approval_id: undefined,
            history: {
                ...first.data.history,
                page: 1,
                next_page: 2,
                records: Array.from({ length: 10 }, (_, index) =>
                    approvalRecord({ id: 12 - index }),
                ),
            },
        };
        transport.get.mockResolvedValueOnce({ ...first, data: firstData });
        render(
            <>
                <button>Outside history</button>
                <TicketApprovalHistory {...props} />
            </>,
        );
        fireEvent.click(
            screen.getByRole('button', { name: /Approval history ·/ }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Load approval history' }),
        );
        await screen.findByText('Request #12');
        let finish!: (value: unknown) => void;
        let fail!: (error: unknown) => void;
        transport.get.mockImplementationOnce(
            () =>
                new Promise((resolve, reject) => {
                    finish = resolve;
                    fail = reject;
                }),
        );
        const older = screen.getByRole('button', { name: 'Older requests' });
        older.focus();
        fireEvent.click(older);
        expect(older).toHaveFocus();
        expect(older).toHaveAttribute('aria-disabled', 'true');
        fireEvent.click(older);
        expect(transport.get).toHaveBeenCalledTimes(2);
        if (outcome === 'focus moved')
            screen.getByRole('button', { name: 'Outside history' }).focus();
        if (outcome === 'cancel') {
            const cancel = screen.getByRole('button', {
                name: 'Cancel history check',
            });
            cancel.focus();
            fireEvent.click(cancel);
            expect(
                screen.getByRole('button', {
                    name: /history check|approval history/,
                }),
            ).toHaveFocus();
        }
        await act(async () => {
            if (outcome === 'failure') fail({ response: { status: 500 } });
            else
                finish({
                    ...response(),
                    data: { ...response().data, target_approval_id: undefined },
                });
        });
        if (outcome === 'last page') {
            await screen.findByText('Request #1');
            expect(older).toHaveFocus();
            expect(older).toHaveAttribute('aria-disabled', 'true');
            expect(screen.getByText(/No older requests/)).toBeInTheDocument();
        } else if (outcome === 'failure') {
            expect(screen.getByRole('alert')).toBeInTheDocument();
            expect(older).toHaveFocus();
        } else if (outcome === 'focus moved') {
            expect(
                screen.getByRole('button', { name: 'Outside history' }),
            ).toHaveFocus();
        }
    },
);
