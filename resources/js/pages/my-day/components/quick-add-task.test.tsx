import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { TaskRequestError } from '../lib/task-api';
import type { MyDayShift } from '../lib/types';
import { QuickAddTask } from './quick-add-task';

const api = vi.hoisted(() => ({
    request: vi.fn(),
    toast: { info: vi.fn(), success: vi.fn() },
}));
vi.mock('../lib/task-api', async (original) => ({
    ...(await original<object>()),
    taskRequest: api.request,
}));
vi.mock('sonner', () => ({ toast: api.toast }));

const shift = {
    id: 7,
    starts_at: '2026-09-12T07:00:00+12:00',
    ends_at: '2026-09-12T15:00:00+12:00',
} as MyDayShift;
const draft = {
    request_id: '7094bd40-3d25-45bb-8b55-263d4d50a287',
    label: 'Prepare activity bag',
    person: '4',
    when: 'anytime',
    scheduled_for: '',
};
function mount(initialTime?: number, initialPerson: 'all' | number = 'all') {
    const onCreated = vi.fn();
    const onOpenChange = vi.fn();
    render(
        <QuickAddTask
            open
            onOpenChange={onOpenChange}
            shift={shift}
            siteName="Test House"
            workerName="Test Worker"
            clients={[{ id: 4, name: 'Test Person' }]}
            initialPerson={initialPerson}
            initialTime={initialTime}
            onCreated={onCreated}
        />,
    );
    return { onCreated, onOpenChange };
}

describe('Quick task recovery', () => {
    beforeEach(() => {
        api.request.mockReset();
        api.toast.success.mockReset();
    });

    it('prefills the exact calendar time and sends that instant when adding a task', async () => {
        const at = Date.parse('2026-09-12T10:00:00+12:00');
        api.request.mockImplementation(async (url: string, method = 'GET') => {
            if (method === 'GET') return { content: null, version: 0 };
            if (url.endsWith('/tasks'))
                return {
                    task: { id: 42, label: 'Selected time task', shift_id: 7 },
                };
            return { content: null, version: 1 };
        });
        const events = mount(at, 4);
        const time = await screen.findByRole('combobox', {
            name: 'Time during this shift',
        });
        expect(time).toHaveTextContent('10:00 am');
        fireEvent.change(
            screen.getByRole('textbox', { name: 'What needs to happen?' }),
            { target: { value: 'Selected time task' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Add task' }));
        await waitFor(() => expect(events.onCreated).toHaveBeenCalledOnce());
        expect(api.request).toHaveBeenCalledWith(
            '/my-day/shifts/7/tasks',
            'POST',
            expect.objectContaining({
                when: 'time',
                scheduled_for: new Date(at).toISOString(),
            }),
        );
    });

    it('restores a private saved draft and retains its command key when confirming the task', async () => {
        api.request.mockImplementation(
            async (
                url: string,
                method = 'GET',
                data?: Record<string, unknown>,
            ) => {
                if (method === 'GET')
                    return {
                        content: draft,
                        version: 2,
                        saved_at: '2026-09-11T23:00:00Z',
                    };
                if (url.endsWith('/tasks'))
                    return {
                        task: { id: 42, label: draft.label, shift_id: 7 },
                    };
                return { content: data?.content, version: 3 };
            },
        );
        const events = mount(Date.parse('2026-09-12T10:00:00+12:00'));
        fireEvent.click(
            await screen.findByRole('button', { name: 'Resume draft' }),
        );
        expect(await screen.findByDisplayValue(draft.label)).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Add task' }));
        await waitFor(() =>
            expect(events.onCreated).toHaveBeenCalledWith(
                expect.objectContaining({ id: 42 }),
            ),
        );
        expect(api.request).toHaveBeenCalledWith(
            '/my-day/shifts/7/tasks',
            'POST',
            expect.objectContaining({
                request_id: draft.request_id,
                client_id: 4,
                task_scope: 'client',
                when: 'anytime',
            }),
        );
        expect(events.onOpenChange).toHaveBeenCalledWith(false);
    });

    it('keeps optional steps in the recovered draft and includes them in one task', async () => {
        const savedStep = {
            id: '08e9cabd-1c37-4f6b-b4d7-f22fe3aef8e6',
            label: 'Pack the drink bottle',
        };
        api.request.mockImplementation(async (url: string, method = 'GET') => {
            if (method === 'GET')
                return {
                    content: { ...draft, steps: [savedStep] },
                    version: 2,
                };
            if (url.endsWith('/tasks'))
                return { task: { id: 42, label: draft.label, shift_id: 7 } };
            return { content: null, version: 3 };
        });
        const events = mount();
        fireEvent.click(
            await screen.findByRole('button', { name: 'Resume draft' }),
        );
        expect(screen.getByRole('textbox', { name: 'Step 1' })).toHaveValue(
            savedStep.label,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Add another step' }),
        );
        fireEvent.change(screen.getByRole('textbox', { name: 'Step 2' }), {
            target: { value: 'Pack the activity cards' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Add task' }));
        await waitFor(() => expect(events.onCreated).toHaveBeenCalledTimes(1));
        const command = api.request.mock.calls.find(([url]) =>
            url.endsWith('/tasks'),
        )?.[2];
        expect(command.steps).toHaveLength(2);
        expect(command.steps[0]).toEqual(savedStep);
        expect(command.steps[1]).toEqual({
            id: expect.any(String),
            label: 'Pack the activity cards',
        });
    });

    it('keeps the form and same request after an uncertain save instead of minting a duplicate', async () => {
        let creates = 0;
        api.request.mockImplementation(async (url: string, method = 'GET') => {
            if (method === 'GET') return { content: draft, version: 2 };
            if (url.endsWith('/tasks')) {
                if (++creates === 1)
                    throw new TaskRequestError(
                        'Connection interrupted',
                        {},
                        true,
                    );
                return { task: { id: 42, label: draft.label, shift_id: 7 } };
            }
            return { content: null, version: 3 };
        });
        const events = mount();
        fireEvent.click(
            await screen.findByRole('button', { name: 'Resume draft' }),
        );
        await screen.findByDisplayValue(draft.label);
        fireEvent.click(screen.getByRole('button', { name: 'Add task' }));
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Connection interrupted',
        );
        expect(events.onCreated).not.toHaveBeenCalled();
        expect(screen.getByLabelText('What needs to happen?')).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry same task' }),
        );
        await waitFor(() => expect(events.onCreated).toHaveBeenCalledTimes(1));
        const commands = api.request.mock.calls
            .filter(([url]) => url.endsWith('/tasks'))
            .map((call) => call[2]);
        expect(commands).toHaveLength(2);
        expect(commands[0]).toEqual(commands[1]);
    });

    it('lets the worker explicitly replace a conflicting draft without losing these answers', async () => {
        let loads = 0;
        api.request.mockImplementation(
            async (
                _url: string,
                method = 'GET',
                input?: { expected_version: number },
            ) => {
                if (method === 'GET')
                    return { content: draft, version: ++loads === 1 ? 2 : 5 };
                if (input?.expected_version === 2)
                    throw new TaskRequestError('Another draft was saved', {
                        draft: 'Changed',
                    });
                return { content: null, version: 6 };
            },
        );
        const events = mount();
        fireEvent.click(
            await screen.findByRole('button', { name: 'Resume draft' }),
        );
        fireEvent.change(screen.getByLabelText('What needs to happen?'), {
            target: { value: 'Keep these task details' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Add task' }));
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Save these answers instead',
            }),
        );
        await waitFor(() =>
            expect(
                screen.queryByRole('button', {
                    name: 'Save these answers instead',
                }),
            ).not.toBeInTheDocument(),
        );
        expect(screen.getByLabelText('What needs to happen?')).toHaveValue(
            'Keep these task details',
        );
        expect(api.request).toHaveBeenLastCalledWith(
            '/my-day/shifts/7/task-draft',
            'PUT',
            expect.objectContaining({
                expected_version: 5,
                content: expect.objectContaining({
                    label: 'Keep these task details',
                    request_id: draft.request_id,
                }),
            }),
        );
        expect(events.onCreated).not.toHaveBeenCalled();
    });

    it('does not submit or discard values when saving the recovery draft fails', async () => {
        api.request.mockImplementation(async (_url: string, method = 'GET') => {
            if (method === 'GET') return { content: draft, version: 2 };
            throw new TaskRequestError('Draft could not be saved');
        });
        const events = mount();
        fireEvent.click(
            await screen.findByRole('button', { name: 'Resume draft' }),
        );
        const title = await screen.findByDisplayValue(draft.label);
        fireEvent.change(title, {
            target: { value: 'Prepare bags and coats' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Add task' }));
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Draft could not be saved',
        );
        expect(title).toHaveValue('Prepare bags and coats');
        expect(events.onOpenChange).not.toHaveBeenCalled();
        expect(events.onCreated).not.toHaveBeenCalled();
        expect(
            api.request.mock.calls.some(([url]) => url.endsWith('/tasks')),
        ).toBe(false);
    });
});
