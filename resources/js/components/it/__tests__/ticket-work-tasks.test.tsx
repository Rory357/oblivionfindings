import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { TicketWorkTasks, type TicketWorkTask } from '../ticket-work-tasks';

const mocks = vi.hoisted(() => ({
    post: vi.fn(),
    patch: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    router: {
        post: mocks.post,
        patch: mocks.patch,
    },
}));

const pendingTask: TicketWorkTask = {
    id: 10,
    title: 'Replace the ward access point',
    description: 'Install the approved replacement and verify roaming.',
    status: 'pending',
    due_at: '2026-08-05T01:00:00Z',
    is_required: true,
    evidence_required: true,
    evidence: null,
    completion_note: null,
    completed_at: null,
    sort_order: 10,
    team: { id: 3, name: 'Infrastructure' },
    assignee: { id: 8, name: 'Taylor Technician' },
    completed_by: null,
    dependencies: [
        { id: 9, title: 'Approve maintenance window', status: 'pending' },
    ],
};

const completedTask: TicketWorkTask = {
    ...pendingTask,
    id: 9,
    title: 'Approve maintenance window',
    description: null,
    status: 'completed',
    is_required: true,
    evidence_required: false,
    evidence: ['CHG-0042'],
    completion_note: 'Approved by the Site manager.',
    completed_at: '2026-08-04T22:00:00Z',
    completed_by: { id: 7, name: 'Morgan Manager' },
    dependencies: [],
};

const props = {
    ticketId: 42,
    tasks: [pendingTask, completedTask],
    canManage: true,
    assignees: [{ id: 8, name: 'Taylor Technician' }],
    teams: [{ id: 3, name: 'Infrastructure' }],
};

describe('TicketWorkTasks', () => {
    beforeEach(() => {
        mocks.post.mockReset();
        mocks.patch.mockReset();
    });
    afterEach(cleanup);

    it('shows owned progress, dependencies and completion evidence', () => {
        render(<TicketWorkTasks {...props} />);

        expect(screen.getByText('Work tasks')).toBeVisible();
        expect(screen.getByText('1 of 2 complete')).toBeVisible();
        expect(screen.getByText('1 required outstanding')).toBeVisible();
        expect(
            screen.getAllByText('Infrastructure', { exact: false }),
        ).toHaveLength(2);
        expect(
            screen.getAllByText('Taylor Technician', { exact: false }),
        ).toHaveLength(2);
        expect(screen.getByText('CHG-0042')).toBeVisible();
        expect(
            screen.getByText(/Complete 1 prerequisite first/i),
        ).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Complete task' }),
        ).toBeDisabled();
    });

    it('does not expose task mutation controls in read-only mode', () => {
        render(<TicketWorkTasks {...props} canManage={false} />);

        expect(screen.getByText('Work tasks')).toBeVisible();
        expect(
            screen.queryByRole('button', { name: 'Add task' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Edit task' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Reopen task' }),
        ).not.toBeInTheDocument();
    });

    it('creates work through the canonical ticket task route', () => {
        render(<TicketWorkTasks {...props} tasks={[]} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add task' }));

        const dialog = screen.getByRole('dialog');
        fireEvent.change(within(dialog).getByLabelText('Task title'), {
            target: { value: 'Verify replacement monitoring' },
        });
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Add task' }),
        );

        expect(mocks.post).toHaveBeenCalledWith(
            '/it/tickets/42/tasks',
            expect.objectContaining({
                title: 'Verify replacement monitoring',
                is_required: true,
                evidence_required: false,
                dependency_ids: [],
            }),
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('reopens completed work only through the reasoned lifecycle', () => {
        render(<TicketWorkTasks {...props} tasks={[completedTask]} />);
        fireEvent.click(screen.getByRole('button', { name: 'Reopen task' }));
        const dialog = screen.getByRole('dialog');
        fireEvent.change(
            within(dialog).getByLabelText('Reason for reopening'),
            {
                target: { value: 'The network change was rolled back.' },
            },
        );
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Reopen task' }),
        );

        expect(mocks.post).toHaveBeenCalledWith(
            '/it/tickets/42/tasks/9/reopen',
            { reason: 'The network change was rolled back.' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('records required evidence through the canonical completion route', () => {
        const task = { ...pendingTask, dependencies: [] };
        render(<TicketWorkTasks {...props} tasks={[task]} />);
        fireEvent.click(screen.getByRole('button', { name: 'Complete task' }));
        const dialog = screen.getByRole('dialog');
        fireEvent.change(within(dialog).getByLabelText('Evidence references'), {
            target: { value: 'MON-0042\nPHOTO-0091' },
        });
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Complete task' }),
        );

        expect(mocks.post).toHaveBeenCalledWith(
            '/it/tickets/42/tasks/10/complete',
            {
                completion_note: null,
                evidence: ['MON-0042', 'PHOTO-0091'],
            },
            expect.objectContaining({ preserveScroll: true }),
        );
    });
});
