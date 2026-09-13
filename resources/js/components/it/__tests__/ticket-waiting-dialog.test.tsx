import { clearItTicketDraftMemory } from '@/hooks/use-it-ticket-draft-memory';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    TicketWaitingDialog,
    requesterWaitingCopy,
    waitingStatusLabel,
} from '../ticket-waiting-dialog';
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { user: { id: 11 } } } }),
    router: { reload: vi.fn(), on: () => () => {}, visit: vi.fn() },
}));
vi.mock('sonner', () => ({ toast: { success: vi.fn() } }));
beforeEach(() => clearItTicketDraftMemory());
afterEach(() => vi.restoreAllMocks());
describe('waiting evidence fields', () => {
    it('requires a reason and submits the chosen vendor dependency and next action', async () => {
        const request = vi.spyOn(axios, 'request').mockResolvedValueOnce({
            status: 200,
            data: {
                status: 'committed',
                data: { id: 42, viewer_user_id: 11, lock_version: 7 },
            },
        });
        render(
            <TicketWaitingDialog
                open
                onOpenChange={() => {}}
                scope="single"
                ticketIds={[42]}
                expectedVersions={{ 42: 6 }}
            />,
        );
        expect(
            screen.getByRole('button', { name: 'Set waiting' }),
        ).toBeDisabled();
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
            target: { value: 'Review the replacement on Friday.' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Set waiting' }));
        await waitFor(() => expect(request).toHaveBeenCalledOnce());
        expect(request.mock.calls[0][0].data).toEqual({
            actor_user_id: 11,
            expected_version: 6,
            status: 'waiting',
            waiting_party: 'vendor',
            waiting_reason: 'Supplier must confirm the replacement serial.',
            next_action: 'Review the replacement on Friday.',
        });
    });
    it('does not overwrite an unsaved reason or displayed version when current props refresh', async () => {
        const request = vi
            .spyOn(axios, 'request')
            .mockResolvedValueOnce({ status: 200, data: {} });
        const base = {
            open: true,
            onOpenChange: () => {},
            scope: 'single' as const,
            ticketIds: [42],
        };
        const view = render(
            <TicketWaitingDialog
                {...base}
                expectedVersions={{ 42: 3 }}
                current={{
                    party: 'vendor',
                    reason: 'Old reason',
                    since: null,
                    since_human: null,
                }}
            />,
        );
        fireEvent.change(screen.getByLabelText('Reason for waiting'), {
            target: { value: 'My unsaved investigation detail' },
        });
        view.rerender(
            <TicketWaitingDialog
                {...base}
                expectedVersions={{ 42: 8 }}
                current={{
                    party: 'vendor',
                    reason: 'Someone else changed this',
                    since: null,
                    since_human: null,
                }}
            />,
        );
        expect(screen.getByLabelText('Reason for waiting')).toHaveValue(
            'My unsaved investigation detail',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Set waiting' }));
        await screen.findByText(/The outcome is unconfirmed/);
        expect(request.mock.calls[0][0].data).toMatchObject({
            expected_version: 3,
            waiting_reason: 'My unsaved investigation detail',
        });
    });
    it('keeps requester labels free of private dependency details', () => {
        expect(waitingStatusLabel('vendor')).toBe(
            'Waiting · Vendor or supplier',
        );
        expect(waitingStatusLabel('other', true)).toBe('Waiting on IT');
        expect(waitingStatusLabel('requester', true)).toBe('Waiting on you');
        expect(requesterWaitingCopy('requester')).toContain(
            'reply in the conversation',
        );
        expect(requesterWaitingCopy('vendor')).not.toContain('vendor');
    });
});
