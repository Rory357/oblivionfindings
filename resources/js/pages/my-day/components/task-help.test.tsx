import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { MyDayShiftTask } from '../lib/types';
import { TaskHelp } from './task-help';

const api = vi.hoisted(() => ({ request: vi.fn() }));
vi.mock('../lib/task-api', () => ({ taskRequest: api.request }));
const task: MyDayShiftTask = {
    id: 7,
    label: 'Prepare activity',
    assigned_to: 1,
    version: 3,
    is_completed: false,
    completed_at: null,
    can_complete: true,
};
const request = {
    ...task,
    can_complete: false,
    help: {
        status: 'requested' as const,
        recipient_id: 2,
        recipient_name: 'Elena Demo',
        reason: 'Need a second person to set up.',
        requested_at: '2026-09-12T09:00:00Z',
        responded_at: null,
    },
};

describe('task help', () => {
    beforeEach(() => api.request.mockReset());
    it('requires an eligible named colleague and preserves the reason after a failed save', async () => {
        api.request
            .mockResolvedValueOnce({
                recipients: [{ id: 2, name: 'Elena Demo' }],
            })
            .mockRejectedValueOnce(new Error('Connection interrupted'));
        const saved = vi.fn();
        render(<TaskHelp task={task} actorId={1} onSaved={saved} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Ask someone for help' }),
        );
        await screen.findByRole('option', { name: 'Elena Demo' });
        expect(
            screen.getByRole('button', { name: 'Request help' }),
        ).toBeDisabled();
        fireEvent.change(screen.getByLabelText('Who can help?'), {
            target: { value: '2' },
        });
        fireEvent.change(screen.getByLabelText('What is stopping the task?'), {
            target: { value: 'Need a second person to set up.' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Request help' }));
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Connection interrupted',
        );
        expect(screen.getByLabelText('What is stopping the task?')).toHaveValue(
            'Need a second person to set up.',
        );
        expect(saved).not.toHaveBeenCalled();
        expect(api.request).toHaveBeenLastCalledWith(
            '/my-day/tasks/7/help',
            'PUT',
            {
                expected_version: 3,
                recipient_id: 2,
                reason: 'Need a second person to set up.',
            },
        );
    });
    it('accepts responsibility without marking the task complete', async () => {
        const saved = vi.fn();
        api.request.mockResolvedValue({
            task: {
                ...request,
                version: 4,
                help: { ...request.help, status: 'accepted' },
            },
        });
        render(<TaskHelp task={request} actorId={2} onSaved={saved} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Accept responsibility' }),
        );
        await waitFor(() =>
            expect(saved).toHaveBeenCalledWith(
                expect.objectContaining({
                    is_completed: false,
                    can_complete: true,
                    help: expect.objectContaining({ status: 'accepted' }),
                }),
            ),
        );
        expect(api.request).toHaveBeenCalledWith(
            '/my-day/tasks/7/help-response',
            'PUT',
            { expected_version: 3, response: 'accepted' },
        );
    });
    it('does not expose recipient response controls to the requester', () => {
        render(
            <TaskHelp
                task={{ ...request, can_complete: true }}
                actorId={1}
                onSaved={vi.fn()}
            />,
        );
        expect(
            screen.queryByRole('button', { name: 'Accept responsibility' }),
        ).not.toBeInTheDocument();
        expect(screen.getByRole('status')).toHaveTextContent(
            'Waiting for Elena Demo to accept',
        );
    });
    it('offers a new request when the accepted colleague is no longer eligible', () => {
        render(
            <TaskHelp
                task={{
                    ...request,
                    can_complete: true,
                    follow_through: null,
                    help: { ...request.help, status: 'accepted' },
                }}
                actorId={1}
                onSaved={vi.fn()}
            />,
        );
        expect(screen.getByRole('status')).toHaveTextContent(
            'Help needs a new owner',
        );
        expect(
            screen.getByRole('button', { name: 'Update help request' }),
        ).toBeEnabled();
    });
});
