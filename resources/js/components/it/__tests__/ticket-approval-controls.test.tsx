import { clearItTicketDraftMemory } from '@/hooks/use-it-ticket-draft-memory';
import { approvalRecord, approvalWork } from '@/test/it-approval-fixtures';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { TicketApprovalControls } from '../ticket-approval-controls';

const transport = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock('axios', () => ({
    default: {
        ...transport,
        isAxiosError: (value: unknown) =>
            typeof value === 'object' && value !== null && 'response' in value,
    },
}));
const uuid = '11111111-1111-4111-8111-111111111111';
const nextUuid = '22222222-2222-4222-8222-222222222222';
const ticket = {
    id: 42,
    reference: 'IT-000042',
    lock_version: 4,
    approval: null,
};
const props = () => ({
    actorId: 7,
    ticket,
    work: approvalWork(),
    onCommitted: vi.fn(),
    onAccessLost: vi.fn(),
    onSessionExpired: vi.fn(),
});
const error = (status: number, code = '') => ({
    response: { status, data: { code } },
});
const ack = (
    operation = 'request',
    status = 'pending',
    replayed = false,
    requestUuid = uuid,
    version = 5,
) => ({
    status: operation === 'request' && !replayed ? 201 : 200,
    data: {
        status: 'committed',
        data: {
            id: 42,
            viewer_user_id: 7,
            request_uuid: requestUuid,
            operation: `approval.${operation}`,
            approval_id: 10,
            approval_status: status,
            lock_version: version,
            changed: true,
            replayed,
        },
    },
});
const current = (work = approvalWork(), version = 5, actorId = 7) => ({
    status: 200,
    data: {
        viewer_user_id: actorId,
        ticket: { id: 42, lock_version: version },
        can: { manage: true },
        approval_work: work,
    },
});
function choose(label: string, name: string) {
    fireEvent.click(screen.getByRole('combobox', { name: label }));
    fireEvent.click(screen.getByRole('option', { name }));
}
function beginRequest() {
    fireEvent.click(screen.getByRole('button', { name: 'Request approval' }));
    choose('Primary approver', 'Eligible manager');
    choose('Absence cover (optional)', 'Cover manager');
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
}
function requestReason(value = 'Private proposed change') {
    fireEvent.change(
        screen.getByRole('textbox', {
            name: 'Why is approval needed? (optional)',
        }),
        { target: { value } },
    );
}
beforeEach(() => {
    clearItTicketDraftMemory();
    sessionStorage.clear();
    transport.get.mockReset();
    transport.post.mockReset();
    vi.spyOn(crypto, 'randomUUID').mockReturnValue(uuid);
});
afterEach(() => {
    cleanup();
    clearItTicketDraftMemory();
    vi.restoreAllMocks();
});

describe('approval workspace and governed forms', () => {
    it('requires a named approver, reviews the proposal, and acknowledges only its exact saved command', async () => {
        const callbacks = props();
        transport.post.mockResolvedValue(ack());
        render(<TicketApprovalControls {...callbacks} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Request approval' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(transport.post).not.toHaveBeenCalled();
        expect(
            screen.getByRole('combobox', { name: 'Primary approver' }),
        ).toHaveAttribute('aria-invalid', 'true');
        choose('Primary approver', 'Eligible manager');
        choose('Absence cover (optional)', 'Cover manager');
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        requestReason();
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(transport.post).not.toHaveBeenCalled();
        expect(screen.getByText('Your proposal')).toBeVisible();
        fireEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'Request approval',
            }),
        );
        await waitFor(() =>
            expect(callbacks.onCommitted).toHaveBeenCalledTimes(1),
        );
        expect(transport.post).toHaveBeenCalledWith(
            '/it/tickets/42/approvals',
            expect.objectContaining({
                actor_user_id: 7,
                expected_version: 4,
                request_uuid: uuid,
                primary_approver_user_id: 8,
                cover_approver_user_id: 9,
                reason: 'Private proposed change',
                expires_at: null,
                remind_at: null,
            }),
            expect.anything(),
        );
        expect(
            screen.getByRole('heading', { name: 'Approval requested' }),
        ).toBeVisible();
    });
    it('requires a rejection reason, retains a lost acknowledgement, and retries the original decision exactly', async () => {
        const callbacks = props();
        callbacks.work = approvalWork({
            can_request: false,
            can_decide: true,
            current: approvalRecord(),
            total: 1,
        });
        transport.post
            .mockRejectedValueOnce(new Error('Connection lost'))
            .mockResolvedValueOnce(ack('decide', 'rejected', true));
        render(<TicketApprovalControls {...callbacks} />);
        fireEvent.click(screen.getByRole('button', { name: 'Reject' }));
        fireEvent.click(screen.getByRole('button', { name: 'Reject request' }));
        expect(transport.post).not.toHaveBeenCalled();
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Reason for rejection' }),
            { target: { value: 'Need the access justification' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Reject request' }));
        await screen.findByText(/approval outcome is unconfirmed/i);
        expect(callbacks.onCommitted).not.toHaveBeenCalled();
        expect(
            screen.getByRole('textbox', { name: 'Reason for rejection' }),
        ).toHaveValue('Need the access justification');
        expect(JSON.stringify(sessionStorage)).not.toContain(
            'Need the access justification',
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry original command' }),
        );
        await waitFor(() =>
            expect(callbacks.onCommitted).toHaveBeenCalledTimes(1),
        );
        expect(transport.post.mock.calls[1].slice(0, 2)).toEqual(
            transport.post.mock.calls[0].slice(0, 2),
        );
        expect(
            screen.getByRole('heading', { name: 'Rejection recorded' }),
        ).toBeVisible();
    });
    it('retains dirty request work on close and reauthorizes explicit Resume before revealing fields', async () => {
        render(<TicketApprovalControls {...props()} />);
        beginRequest();
        requestReason('Retained confidential reason');
        fireEvent.change(screen.getByLabelText(/^Deadline \(optional/), {
            target: { value: '2040-09-10T12:00' },
        });
        fireEvent.change(screen.getByLabelText(/^Reminder \(optional/), {
            target: { value: '2040-09-10T11:00' },
        });
        const reason = screen.getByRole('textbox', {
            name: 'Why is approval needed? (optional)',
        });
        reason.focus();
        fireEvent.keyDown(reason, { key: 'Escape' });
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        await waitFor(() => expect(reason).toHaveFocus());
        fireEvent.keyDown(reason, { key: 'Escape' });
        fireEvent.click(
            screen.getByRole('button', { name: 'Keep draft and close' }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(
            screen.queryByDisplayValue('Retained confidential reason'),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Review retained approval draft 1',
            }),
        );
        expect(
            screen.queryByDisplayValue('Retained confidential reason'),
        ).not.toBeInTheDocument();
        transport.post.mockImplementationOnce(async (_url, body) => ({
            status: 200,
            data: {
                candidate: {
                    kind: 'memory',
                    ...body,
                    context_key: 'ticket:42:approval:new:operation:request',
                    current_ticket_version: 4,
                    authorized: true,
                    capabilities: { submit: true },
                    blocker: null,
                },
            },
        }));
        fireEvent.click(screen.getByRole('button', { name: 'Resume draft 1' }));
        await waitFor(() =>
            expect(
                screen.getByRole('textbox', {
                    name: 'Why is approval needed? (optional)',
                }),
            ).toHaveValue('Retained confidential reason'),
        );
        expect(screen.getByLabelText(/^Deadline \(optional/)).toHaveValue(
            '2040-09-10T12:00',
        );
        expect(screen.getByLabelText(/^Reminder \(optional/)).toHaveValue(
            '2040-09-10T11:00',
        );
        expect(transport.post).toHaveBeenCalledWith(
            '/it/tickets/42/approval-candidates/validate',
            expect.objectContaining({
                actor_user_id: 7,
                fields: expect.objectContaining({
                    primary_approver_user_id: 8,
                    cover_approver_user_id: 9,
                }),
            }),
            expect.anything(),
        );
    });
    it('requires current review and explicit version adoption after a stale request without changing the proposal', async () => {
        const callbacks = props();
        render(<TicketApprovalControls {...callbacks} />);
        beginRequest();
        requestReason();
        transport.post.mockRejectedValueOnce(error(409, 'stale_ticket'));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'Request approval',
            }),
        );
        await screen.findByText(/proposal was not applied/i);
        transport.get.mockResolvedValueOnce(current());
        fireEvent.click(
            screen.getByRole('button', { name: 'Review current approval' }),
        );
        await waitFor(() => expect(transport.get).toHaveBeenCalled());
        await screen.findByRole('button', { name: 'Use reviewed version' });
        expect(transport.post).toHaveBeenCalledTimes(1);
        fireEvent.click(
            screen.getByRole('button', { name: 'Use reviewed version' }),
        );
        expect(transport.post).toHaveBeenCalledTimes(1);
        vi.mocked(crypto.randomUUID).mockReturnValue(nextUuid);
        transport.post.mockResolvedValueOnce(
            ack('request', 'pending', false, nextUuid, 6),
        );
        fireEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'Request approval',
            }),
        );
        await waitFor(() =>
            expect(callbacks.onCommitted).toHaveBeenCalledTimes(1),
        );
        expect(transport.post.mock.calls[1][1]).toMatchObject({
            request_uuid: nextUuid,
            expected_version: 5,
            reason: 'Private proposed change',
        });
    });
    it('cancels an existing approval with a separate recorded reason and its exact target', async () => {
        const callbacks = props();
        callbacks.work = approvalWork({
            can_request: false,
            can_withdraw: true,
            current: approvalRecord(),
            total: 1,
        });
        transport.post.mockResolvedValueOnce(ack('withdraw', 'cancelled'));
        render(<TicketApprovalControls {...callbacks} />);
        fireEvent.click(screen.getByRole('button', { name: 'Cancel request' }));
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Reason for cancellation' }),
            { target: { value: 'The requested access is no longer needed' } },
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Cancel approval request' }),
        );
        await waitFor(() =>
            expect(callbacks.onCommitted).toHaveBeenCalledTimes(1),
        );
        expect(transport.post.mock.calls[0][0]).toBe(
            '/it/tickets/42/approvals/10/withdraw',
        );
        expect(
            screen.getByRole('heading', { name: 'Approval request cancelled' }),
        ).toBeVisible();
    });
    it('hides private fields on session expiry and rejects a review from another actor', async () => {
        const callbacks = props();
        transport.post.mockRejectedValueOnce(error(419));
        render(<TicketApprovalControls {...callbacks} />);
        beginRequest();
        requestReason('Private lost session proposal');
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'Request approval',
            }),
        );
        await waitFor(() =>
            expect(callbacks.onSessionExpired).toHaveBeenCalled(),
        );
        expect(
            screen.queryByText('Private lost session proposal'),
        ).not.toBeInTheDocument();
        transport.get.mockResolvedValueOnce(current(approvalWork(), 4, 88));
        fireEvent.click(
            screen.getByRole('button', { name: 'Review current approval' }),
        );
        await waitFor(() =>
            expect(callbacks.onAccessLost).toHaveBeenCalledTimes(1),
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
    it('shows expiry and active cover without inventing a human verdict, and hides all private work from a requester', () => {
        const callbacks = props();
        const record = approvalRecord({
            status: 'expired',
            expires_at: '2026-09-10T02:00:00Z',
            current_responsibility: null,
        });
        callbacks.work = approvalWork({ current: record, total: 1 });
        const rendered = render(<TicketApprovalControls {...callbacks} />);
        expect(
            screen.getByText(/expiry job has not yet recorded/i),
        ).toBeVisible();
        expect(screen.queryByText('Decision by')).not.toBeInTheDocument();
        rendered.rerender(
            <TicketApprovalControls {...callbacks} work={null} />,
        );
        expect(
            screen.queryByText('Privileged access requires review'),
        ).not.toBeInTheDocument();
        expect(screen.queryByText('Cover manager')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Request approval' }),
        ).not.toBeInTheDocument();
    });
    it('purges private work and ignores a late result when the actor changes', async () => {
        let finish!: (result: ReturnType<typeof ack>) => void;
        transport.post.mockReturnValueOnce(
            new Promise((resolve) => {
                finish = resolve;
            }),
        );
        const callbacks = props();
        const rendered = render(<TicketApprovalControls {...callbacks} />);
        beginRequest();
        requestReason();
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'Request approval',
            }),
        );
        rendered.rerender(
            <TicketApprovalControls {...callbacks} actorId={88} />,
        );
        await act(async () => finish(ack()));
        expect(callbacks.onCommitted).not.toHaveBeenCalled();
        expect(
            screen.queryByText('Private proposed change'),
        ).not.toBeInTheDocument();
    });
});
