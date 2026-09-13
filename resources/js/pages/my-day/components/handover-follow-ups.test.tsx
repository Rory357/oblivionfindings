import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { HandoverFollowUps } from './handover-follow-ups';

const api = vi.hoisted(() => ({ request: vi.fn() }));
vi.mock('../lib/task-api', () => ({ taskRequest: api.request }));
describe('handover follow-ups', () => {
    it('replaces add with the existing linked task and keeps reading separate', async () => {
        api.request.mockResolvedValue({
            task: { id: 21, label: 'Prepare activity', is_completed: false },
        });
        const saved = vi.fn();
        const open = vi.fn();
        render(
            <HandoverFollowUps
                handover={{
                    id: 6,
                    unread: true,
                    follow_ups: [
                        {
                            key: 'a'.repeat(64),
                            label: 'Prepare activity',
                            task_id: null,
                            is_completed: false,
                            can_add: true,
                        },
                    ],
                }}
                onSaved={saved}
                onOpen={open}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Add to my shift' }),
        );
        fireEvent.click(
            await screen.findByRole('button', { name: 'Open task' }),
        );
        expect(open).toHaveBeenCalledWith(21);
        expect(
            screen.queryByRole('button', { name: 'Add to my shift' }),
        ).not.toBeInTheDocument();
        expect(api.request).toHaveBeenCalledWith(
            '/my-day/handovers/6/follow-ups',
            'POST',
            { item_key: 'a'.repeat(64) },
        );
        await waitFor(() => expect(saved).toHaveBeenCalledTimes(1));
    });
    it('offers an existing task without another creation control', () => {
        render(
            <HandoverFollowUps
                handover={{
                    id: 6,
                    follow_ups: [
                        {
                            key: 'b',
                            label: 'Existing work',
                            task_id: 22,
                            is_completed: true,
                            can_add: true,
                        },
                    ],
                }}
                onOpen={vi.fn()}
            />,
        );
        expect(screen.getByText('Follow-up completed')).toBeVisible();
        expect(
            screen.queryByRole('button', { name: 'Add to my shift' }),
        ).not.toBeInTheDocument();
    });
});
