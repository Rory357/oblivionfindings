import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { expect, it, vi } from 'vitest';
import type { MyDayShiftTask } from '../lib/types';
import { TaskDetailDialog } from './task-detail-dialog';

const mocks = vi.hoisted(() => ({
    request: vi.fn(),
    success: vi.fn(),
    error: vi.fn(),
}));
vi.mock('../lib/task-api', () => ({ taskRequest: mocks.request }));
vi.mock('sonner', () => ({
    toast: { success: mocks.success, error: mocks.error },
}));
vi.mock('./task-help', () => ({ TaskHelp: () => null }));
vi.mock('./task-steps', () => ({ TaskSteps: () => null }));

it('closes the modal before offering Undo and reverses the confirmed version', async () => {
    const initial: MyDayShiftTask = {
        id: 8,
        label: 'Prepare activity',
        assigned_to: 1,
        version: 3,
        is_completed: false,
        completed_at: null,
        can_complete: true,
    };
    mocks.request
        .mockResolvedValueOnce({
            task: { ...initial, is_completed: true, version: 4 },
        })
        .mockResolvedValueOnce({
            task: { ...initial, is_completed: false, version: 5 },
        });
    function Harness() {
        const [task, setTask] = useState(initial);
        const [open, setOpen] = useState(true);
        return (
            <>
                <p data-testid="outcome">
                    {task.is_completed ? 'Done' : 'Open'}
                </p>
                {open && (
                    <TaskDetailDialog
                        task={task}
                        personName="Mere Demo"
                        actorId={1}
                        onSaved={setTask}
                        onClose={() => setOpen(false)}
                        onAddNote={() => {}}
                    />
                )}
            </>
        );
    }
    render(<Harness />);
    fireEvent.click(screen.getByRole('button', { name: 'Mark done' }));
    await waitFor(() =>
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
    );
    expect(screen.getByTestId('outcome')).toHaveTextContent('Done');
    mocks.success.mock.calls[0][1].action.onClick();
    await waitFor(() =>
        expect(screen.getByTestId('outcome')).toHaveTextContent('Open'),
    );
    expect(mocks.request).toHaveBeenLastCalledWith(
        '/my-day/tasks/8/completion',
        'PUT',
        { is_completed: false, expected_version: 4 },
    );
});
