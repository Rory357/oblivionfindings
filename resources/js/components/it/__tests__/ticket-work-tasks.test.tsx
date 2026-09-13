import {
    clearItTicketDraftMemory,
    useItTicketDraftMemory,
} from '@/hooks/use-it-ticket-draft-memory';
import {
    act,
    cleanup,
    fireEvent,
    render,
    renderHook,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    taskHistoryResponse,
    taskReadiness,
} from '@/test/it-work-task-fixtures';
import { TicketWorkTasks, type TicketWorkTask } from '../ticket-work-tasks';

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
    approval: null,
    current_completion_id: null,
    readiness: taskReadiness({
        prerequisites: 'blocked',
        can_complete: false,
        can_start: false,
    }),
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
    readiness: taskReadiness({
        completion: 'valid',
        can_complete: false,
        can_edit: false,
        can_reopen: true,
    }),
};

const props = {
    actorId: 7,
    ticketId: 42,
    version: 4,
    canViewWork: true,
    tasks: [pendingTask, completedTask],
    canManage: true,
    taskWork: { storage_ready: true, can_create: true, can_reorder: true },
    assignees: [{ id: 8, name: 'Taylor Technician' }],
    teams: [{ id: 3, name: 'Infrastructure' }],
    onCommitted: vi.fn(),
};
const rejection = (status: number, data: unknown = {}) => ({
    isAxiosError: true,
    response: { status, data },
});
const currentReview = (task: TicketWorkTask, version = 5) => ({
    status: 200,
    data: {
        viewer_user_id: 7,
        ticket: {
            id: 42,
            lock_version: version,
            status: 'open',
            merged_into: null,
        },
        can: { manage: true },
        linked_context: { tasks: [task] },
        assignees: props.assignees,
        teamOptions: props.teams,
        approvals: [],
    },
});
function finishWizard() {
    for (let step = 0; step < 3; step++)
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
}

async function reviewConsequences(taskId = 9, version = 4) {
    vi.mocked(axios.get).mockImplementationOnce(async (_url, config) =>
        taskHistoryResponse({
            nonce: config?.params.review_nonce,
            taskId,
            version,
            readiness: taskReadiness({ can_reopen: taskId === 9 }),
        }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Check task history' }));
    fireEvent.click(
        await screen.findByRole('button', {
            name: 'I have reviewed the consequences',
        }),
    );
}

const allowCreateMemory = async (_url: string, input: unknown) => {
    const body = input as Record<string, unknown>;
    return {
        status: 200,
        data: {
            candidate: {
                kind: 'memory',
                memory_uuid: body.memory_uuid,
                candidate_uuid: body.candidate_uuid,
                actor_user_id: 7,
                purpose: 'task_work',
                context_key: 'ticket:42:task:new:operation:create',
                base_ticket_version: body.base_ticket_version,
                current_ticket_version: 4,
                authorized: true,
                capabilities: { submit: true },
                blocker: null,
            },
        },
    };
};

describe('TicketWorkTasks', () => {
    beforeEach(() => {
        clearItTicketDraftMemory();
        sessionStorage.clear();
        props.onCommitted.mockClear();
        vi.spyOn(axios, 'request').mockImplementation(
            () => new Promise(() => {}),
        );
        vi.spyOn(axios, 'get').mockImplementation(() => new Promise(() => {}));
        vi.spyOn(axios, 'post').mockImplementation(() => new Promise(() => {}));
    });
    afterEach(async () => {
        cleanup();
        await new Promise((resolve) => setTimeout(resolve, 0));
        clearItTicketDraftMemory();
        sessionStorage.clear();
        vi.restoreAllMocks();
    });

    it.each(['create', 'reopen'] as const)(
        'keeps the %s form open when current work cannot fit in RAM, then retains it after explicit space recovery',
        async (operation) => {
            for (let index = 0; index < 20; index++) {
                const older = renderHook(() =>
                    useItTicketDraftMemory({
                        enabled: true,
                        persistenceEnabled: false,
                        actorId: 7,
                        context: {
                            purpose: 'task_work',
                            ticketId: 42,
                            operation,
                            taskId: operation === 'create' ? null : 9,
                        },
                        draft: null,
                        workingSnapshot: {
                            fields:
                                operation === 'create'
                                    ? { title: `Older ${index}` }
                                    : { reason: `Older ${index}` },
                            step_index: 0,
                            base_ticket_version: 4,
                        },
                        workingDirty: true,
                        outcomeUnknown: false,
                    }),
                );
                older.unmount();
            }
            render(
                <TicketWorkTasks
                    {...props}
                    tasks={operation === 'create' ? [] : [completedTask]}
                />,
            );
            fireEvent.click(
                screen.getByRole('button', {
                    name: operation === 'create' ? 'Add task' : 'Reopen task',
                }),
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Start a separate draft' }),
            );
            const label =
                operation === 'create'
                    ? /^Task title/
                    : /^Reason for reopening/;
            fireEvent.change(screen.getByLabelText(label), {
                target: { value: 'Latest work must survive' },
            });
            await screen.findByText(/Browser recovery memory is full/);
            fireEvent.keyDown(screen.getByRole('dialog'), {
                key: 'Escape',
                code: 'Escape',
            });
            fireEvent.click(
                await screen.findByRole('button', {
                    name: 'Keep draft and close',
                }),
            );
            expect(screen.getByRole('dialog')).toBeInTheDocument();
            expect(screen.getByLabelText(label)).toHaveValue(
                'Latest work must survive',
            );
            expect(
                screen.getAllByRole('button', { name: /^Discard draft \d+$/ }),
            ).toHaveLength(20);
            const unload = new Event('beforeunload', { cancelable: true });
            window.dispatchEvent(unload);
            expect(unload.defaultPrevented).toBe(true);
            fireEvent.click(
                screen.getByRole('button', { name: 'Discard draft 1' }),
            );
            fireEvent.click(
                within(await screen.findByRole('alertdialog')).getByRole(
                    'button',
                    { name: 'Discard draft' },
                ),
            );
            await waitFor(() =>
                expect(
                    screen.queryByText(/Browser recovery memory is full/),
                ).not.toBeInTheDocument(),
            );
            fireEvent.keyDown(screen.getByRole('dialog'), {
                key: 'Escape',
                code: 'Escape',
            });
            fireEvent.click(
                await screen.findByRole('button', {
                    name: 'Keep draft and close',
                }),
            );
            await waitFor(() =>
                expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
            );
            expect(
                screen.getAllByRole('button', {
                    name: /^Open retained .* draft \d+$/,
                }),
            ).toHaveLength(20);
            expect(axios.request).not.toHaveBeenCalled();
            expect(sessionStorage.length).toBe(0);
        },
    );

    it.each(['status', 'dependency_ids.0', 'is_required', 'evidence_required'])(
        'focuses and associates the server %s rejection with its editable control',
        async (field) => {
            const message = `Current ${field} choice needs correction.`;
            vi.mocked(axios.request).mockRejectedValueOnce(
                rejection(422, { errors: { [field]: [message] } }),
            );
            render(
                <TicketWorkTasks
                    {...props}
                    tasks={[
                        {
                            ...pendingTask,
                            dependencies: [],
                            readiness: taskReadiness(),
                        },
                    ]}
                />,
            );
            fireEvent.click(screen.getByRole('button', { name: 'Edit task' }));
            fireEvent.change(screen.getByLabelText(/^Task title/), {
                target: { value: 'Proposed task change' },
            });
            finishWizard();
            fireEvent.click(screen.getByRole('button', { name: 'Save task' }));
            const target = () =>
                screen
                    .getByRole('dialog')
                    .querySelector(
                        `[data-task-field="${field.split('.')[0]}"]`,
                    );
            await waitFor(() => expect(target()).toHaveFocus());
            expect(target()).toHaveAttribute('aria-invalid', 'true');
            expect(target()).toHaveAccessibleDescription(message);
            expect(props.onCommitted).not.toHaveBeenCalled();
        },
    );

    it('focuses indexed completion evidence errors and preserves the proposed references', async () => {
        vi.mocked(axios.request).mockRejectedValueOnce(
            rejection(422, {
                errors: {
                    'evidence.0': ['This evidence reference is unavailable.'],
                },
            }),
        );
        render(
            <TicketWorkTasks
                {...props}
                tasks={[
                    {
                        ...pendingTask,
                        dependencies: [],
                        readiness: taskReadiness(),
                    },
                ]}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Complete task' }));
        fireEvent.change(screen.getByLabelText(/^Evidence references/), {
            target: { value: 'KEEP-REFERENCE' },
        });
        fireEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'Complete task',
            }),
        );
        await waitFor(() =>
            expect(screen.getByLabelText(/^Evidence references/)).toHaveFocus(),
        );
        expect(
            screen.getByLabelText(/^Evidence references/),
        ).toHaveAccessibleDescription(
            'This evidence reference is unavailable.',
        );
        expect(screen.getByLabelText(/^Evidence references/)).toHaveValue(
            'KEEP-REFERENCE',
        );
        fireEvent.change(screen.getByLabelText(/^Evidence references/), {
            target: { value: 'CORRECTED-REFERENCE' },
        });
        expect(
            screen.queryByText('This evidence reference is unavailable.'),
        ).not.toBeInTheDocument();
    });

    it('does not consume an unrelated resumed proposal when an older receipt is confirmed', async () => {
        const olderUuid = '11111111-1111-4111-8111-111111111111';
        const first = render(<TicketWorkTasks {...props} tasks={[]} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add task' }));
        fireEvent.change(screen.getByLabelText(/^Task title/), {
            target: { value: 'Separate newer unsent proposal' },
        });
        first.unmount();
        await act(async () => {});
        sessionStorage.setItem(
            'it.pending-task-command.v1.7:42:create:collection',
            JSON.stringify([olderUuid]),
        );
        render(<TicketWorkTasks {...props} tasks={[]} />);
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Open retained create draft 1',
            }),
        );
        vi.mocked(axios.post).mockImplementation(allowCreateMemory);
        fireEvent.click(screen.getByRole('button', { name: 'Resume draft 1' }));
        await waitFor(() =>
            expect(screen.getByLabelText(/^Task title/)).toHaveValue(
                'Separate newer unsent proposal',
            ),
        );
        vi.mocked(axios.get).mockResolvedValueOnce({
            status: 200,
            data: {
                status: 'committed',
                data: {
                    id: 42,
                    viewer_user_id: 7,
                    request_uuid: olderUuid,
                    operation: 'task.create',
                    task_id: 44,
                    lock_version: 5,
                    changed: true,
                    replayed: true,
                },
            },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Check result 1' }));
        await screen.findByText(
            /Your newer unsaved work is available in Task work to recover/,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Done' }));
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Open retained create draft 1',
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Resume draft 1' }));
        await waitFor(() =>
            expect(screen.getByLabelText(/^Task title/)).toHaveValue(
                'Separate newer unsent proposal',
            ),
        );
        expect(axios.request).not.toHaveBeenCalled();
    });

    it('upgrades a matching opaque unknown command after 404 to its freshly authorized original RAM body', async () => {
        vi.mocked(axios.request).mockRejectedValueOnce(rejection(503));
        const first = render(<TicketWorkTasks {...props} tasks={[]} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add task' }));
        fireEvent.change(screen.getByLabelText(/^Task title/), {
            target: { value: 'Original uncertain proposal' },
        });
        finishWizard();
        fireEvent.click(screen.getByRole('button', { name: 'Add task' }));
        await screen.findByRole('button', { name: 'Retry original command' });
        const original = structuredClone(
            vi.mocked(axios.request).mock.calls[0][0].data,
        );
        first.unmount();
        await act(async () => {});
        render(<TicketWorkTasks {...props} tasks={[]} />);
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Open retained create draft 1',
            }),
        );
        vi.mocked(axios.get).mockRejectedValueOnce(rejection(404));
        fireEvent.click(screen.getByRole('button', { name: 'Check result 1' }));
        await screen.findByText(/No saved result is available yet/);
        vi.mocked(axios.post).mockImplementationOnce(allowCreateMemory);
        fireEvent.click(screen.getByRole('button', { name: 'Resume draft 1' }));
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Retry original command',
            }),
        );
        await waitFor(() => expect(axios.request).toHaveBeenCalledTimes(2));
        expect(vi.mocked(axios.request).mock.calls[1][0].data).toEqual(
            original,
        );
        expect(props.onCommitted).not.toHaveBeenCalled();
    });

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
            screen.getByText(/Review the current prerequisite blockers/),
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

    it('reviews all steps before creating with the original actor and ticket version', async () => {
        render(<TicketWorkTasks {...props} tasks={[]} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add task' }));

        const dialog = screen.getByRole('dialog');
        fireEvent.change(within(dialog).getByLabelText(/^Task title/), {
            target: { value: 'Verify replacement monitoring' },
        });
        for (let step = 0; step < 3; step++) {
            fireEvent.click(
                within(dialog).getByRole('button', { name: 'Continue' }),
            );
            expect(axios.request).not.toHaveBeenCalled();
        }
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Add task' }),
        );
        await waitFor(() =>
            expect(axios.request).toHaveBeenCalledWith(
                expect.objectContaining({
                    url: '/it/tickets/42/tasks',
                    method: 'post',
                    data: expect.objectContaining({
                        actor_user_id: 7,
                        expected_version: 4,
                        request_uuid: expect.any(String),
                        title: 'Verify replacement monitoring',
                        is_required: true,
                        evidence_required: false,
                        dependency_ids: [],
                    }),
                }),
            ),
        );
    });

    it('reopens completed work only through the reasoned lifecycle', async () => {
        render(<TicketWorkTasks {...props} tasks={[completedTask]} />);
        fireEvent.click(screen.getByRole('button', { name: 'Reopen task' }));
        const dialog = screen.getByRole('dialog');
        fireEvent.change(
            within(dialog).getByLabelText(/^Reason for reopening/),
            {
                target: { value: 'The network change was rolled back.' },
            },
        );
        await reviewConsequences();
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Reopen task' }),
        );

        await waitFor(() =>
            expect(axios.request).toHaveBeenCalledWith(
                expect.objectContaining({
                    url: '/it/tickets/42/tasks/9/reopen',
                    method: 'post',
                    data: expect.objectContaining({
                        actor_user_id: 7,
                        expected_version: 4,
                        request_uuid: expect.any(String),
                        reason: 'The network change was rolled back.',
                    }),
                }),
            ),
        );
    });

    it('records required evidence through the canonical completion route', async () => {
        const task = {
            ...pendingTask,
            dependencies: [],
            readiness: taskReadiness(),
        };
        render(<TicketWorkTasks {...props} tasks={[task]} />);
        fireEvent.click(screen.getByRole('button', { name: 'Complete task' }));
        const dialog = screen.getByRole('dialog');
        fireEvent.change(
            within(dialog).getByLabelText(/^Evidence references/),
            {
                target: { value: 'MON-0042\nPHOTO-0091' },
            },
        );
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Complete task' }),
        );

        await waitFor(() =>
            expect(axios.request).toHaveBeenCalledWith(
                expect.objectContaining({
                    url: '/it/tickets/42/tasks/10/complete',
                    method: 'post',
                    data: expect.objectContaining({
                        completion_note: null,
                        evidence: ['MON-0042', 'PHOTO-0091'],
                        actor_user_id: 7,
                        expected_version: 4,
                        request_uuid: expect.any(String),
                    }),
                }),
            ),
        );
    });

    it('focuses missing title and never submits while advancing or validating steps', async () => {
        render(<TicketWorkTasks {...props} tasks={[]} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add task' }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        await waitFor(() =>
            expect(screen.getByLabelText(/^Task title/)).toHaveFocus(),
        );
        expect(
            screen.getAllByText('Enter a task title.').length,
        ).toBeGreaterThan(0);
        expect(axios.request).not.toHaveBeenCalled();
        fireEvent.change(screen.getByLabelText(/^Task title/), {
            target: { value: 'Reviewed work' },
        });
        finishWizard();
        expect(axios.request).not.toHaveBeenCalled();
        expect(
            screen.queryByText('Enter a task title.'),
        ).not.toBeInTheDocument();
    });

    it('preserves a rejected proposal and permits correction without a success acknowledgement', async () => {
        vi.mocked(axios.request).mockRejectedValueOnce(
            rejection(422, {
                errors: { title: ['Use a distinct task title.'] },
            }),
        );
        render(<TicketWorkTasks {...props} tasks={[]} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add task' }));
        fireEvent.change(screen.getByLabelText(/^Task title/), {
            target: { value: 'Keep this proposal' },
        });
        finishWizard();
        fireEvent.click(screen.getByRole('button', { name: 'Add task' }));
        await screen.findAllByText('Use a distinct task title.');
        expect(props.onCommitted).not.toHaveBeenCalled();
        expect(screen.getByLabelText(/^Task title/)).toHaveValue(
            'Keep this proposal',
        );
        expect(screen.getByLabelText(/^Task title/)).toBeEnabled();
        fireEvent.change(screen.getByLabelText(/^Task title/), {
            target: { value: 'Corrected proposal' },
        });
        expect(
            screen.queryByText('Use a distinct task title.'),
        ).not.toBeInTheDocument();
    });

    it('keeps the exact unknown command frozen through retry and closing', async () => {
        vi.mocked(axios.request).mockRejectedValueOnce(rejection(503));
        render(<TicketWorkTasks {...props} tasks={[]} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add task' }));
        fireEvent.change(screen.getByLabelText(/^Task title/), {
            target: { value: 'Private uncertain work' },
        });
        finishWizard();
        fireEvent.click(screen.getByRole('button', { name: 'Add task' }));
        await screen.findByRole('button', { name: 'Retry original command' });
        const original = structuredClone(
            vi.mocked(axios.request).mock.calls[0][0].data,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry original command' }),
        );
        await waitFor(() => expect(axios.request).toHaveBeenCalledTimes(2));
        expect(vi.mocked(axios.request).mock.calls[1][0].data).toEqual(
            original,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel wait' }));
        fireEvent.keyDown(screen.getByRole('dialog'), {
            key: 'Escape',
            code: 'Escape',
        });
        fireEvent.click(
            await screen.findByRole('button', { name: 'Keep draft and close' }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(screen.getByText('Task work to recover')).toBeVisible();
        expect(JSON.stringify(sessionStorage)).not.toContain(
            'Private uncertain work',
        );
        expect(props.onCommitted).not.toHaveBeenCalled();
    });

    it('uses explicit current review and applies only the changed edit fields', async () => {
        const task = {
            ...pendingTask,
            dependencies: [],
            readiness: taskReadiness(),
        };
        vi.mocked(axios.request).mockRejectedValueOnce(
            rejection(409, {
                code: 'stale_ticket',
                errors: { expected_version: ['Review the current version.'] },
            }),
        );
        vi.mocked(axios.get).mockResolvedValueOnce(
            currentReview({
                ...task,
                description: 'Competing technician description',
            }),
        );
        render(<TicketWorkTasks {...props} tasks={[task]} />);
        fireEvent.click(screen.getByRole('button', { name: 'Edit task' }));
        fireEvent.change(screen.getByLabelText(/^Task title/), {
            target: { value: 'My proposed title' },
        });
        finishWizard();
        fireEvent.click(screen.getByRole('button', { name: 'Save task' }));
        fireEvent.click(
            await screen.findByRole('button', { name: 'Review current task' }),
        );
        await screen.findByText('Competing technician description');
        fireEvent.click(
            screen.getByRole('button', { name: 'Use reviewed version' }),
        );
        expect(axios.request).toHaveBeenCalledTimes(1);
        fireEvent.click(screen.getByRole('button', { name: 'Save task' }));
        await waitFor(() => expect(axios.request).toHaveBeenCalledTimes(2));
        expect(vi.mocked(axios.request).mock.calls[1][0].data).toEqual({
            actor_user_id: 7,
            request_uuid: expect.any(String),
            expected_version: 5,
            title: 'My proposed title',
        });
    });

    it('conceals session-expired fields and keeps the hard-reload warning', async () => {
        const onSessionExpired = vi.fn();
        vi.mocked(axios.request).mockRejectedValueOnce(rejection(419));
        render(
            <TicketWorkTasks
                {...props}
                tasks={[completedTask]}
                onSessionExpired={onSessionExpired}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Reopen task' }));
        fireEvent.change(screen.getByLabelText(/^Reason for reopening/), {
            target: { value: 'Private recovery reason' },
        });
        await reviewConsequences();
        fireEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'Reopen task',
            }),
        );
        await screen.findByText(/Sign in with the same account/);
        expect(
            screen.queryByDisplayValue('Private recovery reason'),
        ).not.toBeInTheDocument();
        const event = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(event);
        expect(event.defaultPrevented).toBe(true);
        expect(props.onCommitted).not.toHaveBeenCalled();
        expect(onSessionExpired).toHaveBeenCalledOnce();
    });

    it('purges the private register and open proposal after confirmed access loss', async () => {
        const onAccessLost = vi.fn();
        vi.mocked(axios.request).mockRejectedValueOnce(rejection(403));
        render(
            <TicketWorkTasks
                {...props}
                tasks={[completedTask]}
                onAccessLost={onAccessLost}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Reopen task' }));
        fireEvent.change(screen.getByLabelText(/^Reason for reopening/), {
            target: { value: 'Private recovery reason' },
        });
        await reviewConsequences();
        fireEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'Reopen task',
            }),
        );
        await waitFor(() => expect(onAccessLost).toHaveBeenCalledOnce());
        expect(screen.queryByText(completedTask.title)).not.toBeInTheDocument();
        expect(
            screen.queryByDisplayValue('Private recovery reason'),
        ).not.toBeInTheDocument();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('omits redacted work rather than claiming zero, including an open private dialog', async () => {
        const page = render(<TicketWorkTasks {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Edit task' }));
        fireEvent.change(screen.getByLabelText(/^Task title/), {
            target: { value: 'Private draft to purge' },
        });
        page.rerender(
            <TicketWorkTasks
                {...props}
                canViewWork={false}
                canManage={false}
                tasks={[]}
            />,
        );
        await act(async () => {});
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.queryByText('Work tasks')).not.toBeInTheDocument();
        expect(screen.queryByText('No work tasks yet')).not.toBeInTheDocument();
        page.rerender(<TicketWorkTasks {...props} />);
        expect(
            screen.queryByText('Task work to recover'),
        ).not.toBeInTheDocument();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('requires meaningful evidence and trims each reference before completing', async () => {
        render(
            <TicketWorkTasks
                {...props}
                tasks={[
                    {
                        ...pendingTask,
                        dependencies: [],
                        readiness: taskReadiness(),
                    },
                ]}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Complete task' }));
        fireEvent.change(screen.getByLabelText(/^Evidence references/), {
            target: { value: '   \n  ' },
        });
        fireEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'Complete task',
            }),
        );
        await waitFor(() =>
            expect(screen.getByLabelText(/^Evidence references/)).toHaveFocus(),
        );
        expect(axios.request).not.toHaveBeenCalled();
        fireEvent.change(screen.getByLabelText(/^Evidence references/), {
            target: { value: '  MON-42  \n\n PHOTO-91 ' },
        });
        fireEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'Complete task',
            }),
        );
        await waitFor(() => expect(axios.request).toHaveBeenCalledOnce());
        const submitted = vi.mocked(axios.request).mock.calls[0][0]
            .data as Record<string, unknown>;
        expect(submitted.evidence).toEqual(['MON-42', 'PHOTO-91']);
    });

    it('recovers unsent task text after page traversal only after fresh actor and context proof', async () => {
        const first = render(<TicketWorkTasks {...props} tasks={[]} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add task' }));
        fireEvent.change(screen.getByLabelText(/^Task title/), {
            target: { value: 'Private traversal proposal' },
        });
        first.unmount();
        await act(async () => {});
        render(<TicketWorkTasks {...props} tasks={[]} />);
        expect(
            screen.queryByText('Private traversal proposal'),
        ).not.toBeInTheDocument();
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Open retained create draft 1',
            }),
        );
        expect(screen.getByLabelText(/^Task title/)).toHaveValue('');
        vi.mocked(axios.post).mockImplementationOnce(async (_url, input) => {
            const body = input as Record<string, unknown>;
            return {
                status: 200,
                data: {
                    candidate: {
                        kind: 'memory',
                        memory_uuid: body.memory_uuid,
                        candidate_uuid: body.candidate_uuid,
                        actor_user_id: 7,
                        purpose: 'task_work',
                        context_key: 'ticket:42:task:new:operation:create',
                        base_ticket_version: 4,
                        current_ticket_version: 4,
                        authorized: true,
                        capabilities: { submit: true },
                        blocker: null,
                    },
                },
            };
        });
        fireEvent.click(screen.getByRole('button', { name: 'Resume draft 1' }));
        await waitFor(() =>
            expect(screen.getByLabelText(/^Task title/)).toHaveValue(
                'Private traversal proposal',
            ),
        );
        expect(axios.post).toHaveBeenCalledWith(
            '/it/tickets/42/task-candidates/validate',
            expect.objectContaining({
                actor_user_id: 7,
                operation: 'create',
                task_id: null,
                fields: expect.objectContaining({
                    title: 'Private traversal proposal',
                }),
                base_ticket_version: 4,
            }),
            expect.any(Object),
        );
        expect(axios.request).not.toHaveBeenCalled();
    });

    it('keeps the current ineligible assignee visible and permits removal of a cancelled prerequisite', async () => {
        const task = {
            ...pendingTask,
            dependencies: [
                {
                    id: 9,
                    title: 'Cancelled prerequisite',
                    status: 'cancelled' as const,
                },
            ],
        };
        render(
            <TicketWorkTasks
                {...props}
                assignees={[]}
                tasks={[
                    task,
                    {
                        ...completedTask,
                        title: 'Cancelled prerequisite',
                        status: 'cancelled',
                    },
                ]}
            />,
        );
        fireEvent.click(
            screen.getAllByRole('button', { name: 'Edit task' })[0],
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(
            screen.getByText(/The current assignment is retained/),
        ).toBeVisible();
        expect(
            screen.getByRole('combobox', { name: 'Assigned technician' }),
        ).toHaveTextContent('Taylor Technician');
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        const dependency = screen.getByRole('checkbox', {
            name: /Cancelled prerequisite/,
        });
        expect(dependency).toBeChecked();
        expect(dependency).toBeEnabled();
        fireEvent.click(dependency);
        expect(dependency).not.toBeChecked();
        expect(dependency).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.change(
            await screen.findByLabelText(/^Reason for this change/),
            {
                target: {
                    value: 'Remove the cancelled prerequisite after review.',
                },
            },
        );
        finishWizard();
        await reviewConsequences(10);
        fireEvent.click(screen.getByRole('button', { name: 'Save task' }));
        await waitFor(() => expect(axios.request).toHaveBeenCalledOnce());
        expect(vi.mocked(axios.request).mock.calls[0][0].data).toEqual({
            actor_user_id: 7,
            expected_version: 4,
            request_uuid: expect.any(String),
            dependency_ids: [],
            reason: 'Remove the cancelled prerequisite after review.',
        });
    });

    it('reorders the full register with labelled controls and a separate explicit save', async () => {
        render(<TicketWorkTasks {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Reorder tasks' }));
        const move = screen.getByRole('button', { name: 'Move task 10 down' });
        move.focus();
        expect(move).toHaveFocus();
        fireEvent.click(move);
        expect(
            screen.getByRole('button', { name: 'Move task 10 down' }),
        ).toBeDisabled();
        expect(axios.request).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Save task order' }),
        );
        await waitFor(() => expect(axios.request).toHaveBeenCalledOnce());
        expect(vi.mocked(axios.request).mock.calls[0][0]).toMatchObject({
            method: 'patch',
            url: '/it/tickets/42/tasks/reorder',
            data: {
                actor_user_id: 7,
                expected_version: 4,
                ordered_ids: [9, 10],
            },
        });
    });

    it('retains a dirty form when closing is cancelled and discards only on explicit confirmation', async () => {
        render(<TicketWorkTasks {...props} tasks={[]} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add task' }));
        fireEvent.change(screen.getByLabelText(/^Task title/), {
            target: { value: 'Deliberate discard' },
        });
        fireEvent.keyDown(screen.getByRole('dialog'), {
            key: 'Escape',
            code: 'Escape',
        });
        fireEvent.click(
            within(await screen.findByRole('alertdialog')).getByRole('button', {
                name: 'Cancel',
            }),
        );
        expect(screen.getByLabelText(/^Task title/)).toHaveValue(
            'Deliberate discard',
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard changes' }),
        );
        fireEvent.click(
            within(await screen.findByRole('alertdialog')).getByRole('button', {
                name: 'Discard changes',
            }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(
            screen.queryByText('Task work to recover'),
        ).not.toBeInTheDocument();
        expect(axios.request).not.toHaveBeenCalled();
    });

    it('requires explicit reconciliation when a stale order review contains a new task', async () => {
        const added = {
            ...pendingTask,
            id: 11,
            title: 'New concurrent task',
            dependencies: [],
        };
        vi.mocked(axios.request).mockRejectedValueOnce(
            rejection(409, {
                code: 'stale_ticket',
                errors: { expected_version: ['Review current work.'] },
            }),
        );
        const review = currentReview(added);
        review.data.linked_context.tasks = [pendingTask, completedTask, added];
        vi.mocked(axios.get).mockResolvedValueOnce(review);
        render(<TicketWorkTasks {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Reorder tasks' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Move task 10 down' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Save task order' }),
        );
        fireEvent.click(
            await screen.findByRole('button', { name: 'Review current task' }),
        );
        fireEvent.click(
            await screen.findByRole('button', { name: 'Use reviewed version' }),
        );
        expect(
            screen.getByRole('button', { name: 'Save task order' }),
        ).toBeDisabled();
        fireEvent.submit(screen.getByRole('dialog').querySelector('form')!);
        expect(axios.request).toHaveBeenCalledTimes(1);
        fireEvent.click(
            screen.getByRole('button', { name: 'Reconcile task list' }),
        );
        expect(
            screen.getByRole('button', { name: 'Save task order' }),
        ).toBeEnabled();
        expect(axios.request).toHaveBeenCalledTimes(1);
        fireEvent.click(
            screen.getByRole('button', { name: 'Save task order' }),
        );
        await waitFor(() => expect(axios.request).toHaveBeenCalledTimes(2));
        expect(vi.mocked(axios.request).mock.calls[1][0].data).toMatchObject({
            expected_version: 5,
            ordered_ids: [9, 10, 11],
        });
    });

    it('reports completion only after the exact committed actor, command and version acknowledgement', async () => {
        vi.mocked(axios.request).mockImplementationOnce(async (config) => ({
            status: 200,
            data: {
                status: 'committed',
                data: {
                    id: 42,
                    viewer_user_id: 7,
                    request_uuid: (config.data as Record<string, unknown>)
                        .request_uuid,
                    operation: 'task.reopen',
                    task_id: 9,
                    lock_version: 5,
                    changed: true,
                    replayed: false,
                },
            },
        }));
        render(<TicketWorkTasks {...props} tasks={[completedTask]} />);
        fireEvent.click(screen.getByRole('button', { name: 'Reopen task' }));
        fireEvent.change(screen.getByLabelText(/^Reason for reopening/), {
            target: { value: 'A follow-up check is required.' },
        });
        await reviewConsequences();
        fireEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'Reopen task',
            }),
        );
        await waitFor(() => expect(props.onCommitted).toHaveBeenCalledOnce());
        expect(props.onCommitted).toHaveBeenCalledWith(
            expect.objectContaining({
                task_id: 9,
                operation: 'task.reopen',
                lock_version: 5,
            }),
        );
        expect(sessionStorage.length).toBe(0);
        fireEvent.click(screen.getByRole('button', { name: 'Done' }));
        expect(
            screen.queryByText('Task work to recover'),
        ).not.toBeInTheDocument();
    });
});
