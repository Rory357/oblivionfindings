import { clearItTicketDraftMemory } from '@/hooks/use-it-ticket-draft-memory';
import {
    taskCompletionEntry,
    taskHistoryResponse,
    taskReadiness,
} from '@/test/it-work-task-fixtures';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { TicketWorkTasks, type TicketWorkTask } from '../ticket-work-tasks';

const task: TicketWorkTask = {
    id: 10,
    title: 'Verify workstation access',
    description: 'Private task definition',
    status: 'pending',
    due_at: null,
    is_required: false,
    evidence_required: false,
    evidence: null,
    completion_note: null,
    completed_at: null,
    sort_order: 10,
    team: null,
    assignee: null,
    completed_by: null,
    dependencies: [],
    current_completion_id: null,
    approval: null,
    readiness: taskReadiness({ can_cancel: true }),
};
const props = {
    actorId: 7,
    ticketId: 42,
    version: 4,
    canViewWork: true,
    canManage: true,
    taskWork: { storage_ready: true, can_create: true, can_reorder: true },
    tasks: [task],
    assignees: [],
    teams: [],
    approvals: [{ id: 20, status: 'approved' }],
    onCommitted: vi.fn(),
};
function continueWizard() {
    for (let step = 0; step < 3; step++)
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
}
async function consequences() {
    fireEvent.click(screen.getByRole('button', { name: 'Check task history' }));
    fireEvent.click(
        await screen.findByRole('button', {
            name: 'I have reviewed the consequences',
        }),
    );
}
beforeEach(() => {
    clearItTicketDraftMemory();
    sessionStorage.clear();
    props.onCommitted.mockClear();
    vi.spyOn(axios, 'request').mockImplementation(() => new Promise(() => {}));
    vi.spyOn(axios, 'post').mockImplementation(() => new Promise(() => {}));
    vi.spyOn(axios, 'get').mockImplementation(async (_url, config) =>
        taskHistoryResponse({
            nonce: config?.params.review_nonce,
            taskId: 10,
        }),
    );
});
afterEach(() => {
    cleanup();
    clearItTicketDraftMemory();
    sessionStorage.clear();
    vi.restoreAllMocks();
});

describe('Task lifecycle and immutable completion evidence', () => {
    it('does not infer creation or reorder availability from an empty or partially upgraded register', () => {
        const page = render(
            <TicketWorkTasks {...props} tasks={[]} taskWork={undefined} />,
        );
        expect(screen.getByRole('button', { name: 'Add task' })).toBeDisabled();
        expect(
            screen.getByText(
                /unavailable until current work capabilities are ready/,
            ),
        ).toBeVisible();
        page.rerender(
            <TicketWorkTasks
                {...props}
                tasks={[task, { ...task, id: 11 }]}
                taskWork={{
                    storage_ready: true,
                    can_create: false,
                    can_reorder: false,
                }}
            />,
        );
        expect(screen.getByRole('button', { name: 'Add task' })).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Reorder tasks' }),
        ).toBeDisabled();
        expect(axios.request).not.toHaveBeenCalled();
    });

    it('binds a new task to only a provided same-ticket approval generation', async () => {
        render(<TicketWorkTasks {...props} tasks={[]} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add task' }));
        fireEvent.change(screen.getByLabelText(/^Task title/), {
            target: { value: 'Approved follow-up' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(
            screen.getByRole('combobox', { name: 'Approval request' }),
        );
        expect(
            screen.getAllByRole('option').map((option) => option.textContent),
        ).toEqual(['No linked approval', 'Request #20 · approved']);
        fireEvent.click(
            screen.getByRole('option', { name: 'Request #20 · approved' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(screen.getByText(/Linked approval request: 20/)).toBeVisible();
        expect(axios.request).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Add task' }));
        await waitFor(() => expect(axios.request).toHaveBeenCalledOnce());
        expect(vi.mocked(axios.request).mock.calls[0][0].data).toMatchObject({
            title: 'Approved follow-up',
            approval_id: 20,
            expected_version: 4,
        });
    });

    it.each([
        {
            label: 'Cancel',
            status: 'pending' as const,
            next: 'cancelled',
            verdict: taskReadiness({ can_cancel: true }),
        },
        {
            label: 'Restore',
            status: 'cancelled' as const,
            next: 'pending',
            verdict: taskReadiness({
                can_complete: false,
                can_start: false,
                can_restore: true,
            }),
        },
    ])(
        '$label is an explicit reasoned update after fresh consequence review',
        async ({ label, status, next, verdict }) => {
            render(
                <TicketWorkTasks
                    {...props}
                    tasks={[{ ...task, status, readiness: verdict }]}
                />,
            );
            fireEvent.click(
                screen.getByRole('button', { name: `${label} task` }),
            );
            const dialog = screen.getByRole('dialog');
            const reason = within(dialog).getByLabelText(
                /^Reason for this change/,
            );
            fireEvent.change(reason, {
                target: { value: `${label} after reviewing the current work.` },
            });
            continueWizard();
            expect(
                within(dialog).getByRole('button', { name: `${label} task` }),
            ).toBeDisabled();
            expect(axios.request).not.toHaveBeenCalled();
            await consequences();
            expect(axios.request).not.toHaveBeenCalled();
            fireEvent.click(
                within(dialog).getByRole('button', { name: `${label} task` }),
            );
            await waitFor(() => expect(axios.request).toHaveBeenCalledOnce());
            expect(vi.mocked(axios.request).mock.calls[0][0]).toMatchObject({
                method: 'patch',
                url: '/it/tickets/42/tasks/10',
                data: {
                    actor_user_id: 7,
                    expected_version: 4,
                    request_uuid: expect.any(String),
                    status: next,
                    reason: `${label} after reviewing the current work.`,
                },
            });
            expect(
                vi.mocked(axios.request).mock.calls[0][0].data,
            ).not.toHaveProperty('is_required');
        },
    );

    it('keeps required work required until an explicit reasoned proposal changes that requirement', async () => {
        render(
            <TicketWorkTasks
                {...props}
                tasks={[
                    { ...task, is_required: true, readiness: taskReadiness() },
                ]}
            />,
        );
        expect(
            screen.getByRole('button', { name: 'Cancel task' }),
        ).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Edit task' }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(
            screen.getByRole('checkbox', {
                name: /Required before ticket settlement/,
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        const reason = await screen.findByLabelText(/^Reason for this change/);
        await waitFor(() => expect(reason).toHaveFocus());
        expect(reason).toHaveAttribute('aria-invalid', 'true');
        expect(axios.request).not.toHaveBeenCalled();
        fireEvent.change(reason, {
            target: {
                value: 'The approved scope no longer requires this work.',
            },
        });
        continueWizard();
        await consequences();
        fireEvent.click(screen.getByRole('button', { name: 'Save task' }));
        await waitFor(() => expect(axios.request).toHaveBeenCalledOnce());
        expect(vi.mocked(axios.request).mock.calls[0][0].data).toMatchObject({
            is_required: false,
            reason: 'The approved scope no longer requires this work.',
            expected_version: 4,
        });
        expect(
            vi.mocked(axios.request).mock.calls[0][0].data,
        ).not.toHaveProperty('status');
    });

    it('requires a fresh current task review and separate adoption when the consequence graph advanced', async () => {
        vi.mocked(axios.get).mockImplementation(async (url, config) => {
            if (url.endsWith('/history'))
                return taskHistoryResponse({
                    nonce: config?.params.review_nonce,
                    taskId: 10,
                    version: 5,
                });
            return {
                status: 200,
                data: {
                    viewer_user_id: 7,
                    ticket: {
                        id: 42,
                        lock_version: 5,
                        status: 'open',
                        merged_into: null,
                    },
                    can: { manage: true },
                    linked_context: {
                        tasks: [
                            {
                                ...task,
                                description: 'Competing saved description',
                            },
                        ],
                    },
                    assignees: [],
                    teamOptions: [],
                    approvals: props.approvals,
                },
            };
        });
        render(<TicketWorkTasks {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Cancel task' }));
        fireEvent.change(screen.getByLabelText(/^Reason for this change/), {
            target: { value: 'Cancel this optional follow-up.' },
        });
        continueWizard();
        fireEvent.click(
            screen.getByRole('button', { name: 'Check task history' }),
        );
        fireEvent.click(
            await screen.findByRole('button', { name: 'Review current task' }),
        );
        const adopt = await screen.findByRole('button', {
            name: 'Use reviewed version',
        });
        expect(axios.request).not.toHaveBeenCalled();
        expect(screen.getByText('Competing saved description')).toBeVisible();
        fireEvent.click(adopt);
        expect(axios.request).not.toHaveBeenCalled();
        // Adoption keeps the proposal and incorporates untouched current fields.
        expect(
            screen.getAllByText('Cancel this optional follow-up.')[0],
        ).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'I have reviewed the consequences',
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel task' }));
        await waitFor(() => expect(axios.request).toHaveBeenCalledOnce());
        expect(vi.mocked(axios.request).mock.calls[0][0].data).toMatchObject({
            expected_version: 5,
            status: 'cancelled',
            reason: 'Cancel this optional follow-up.',
        });
        expect(
            vi.mocked(axios.request).mock.calls[0][0].data,
        ).not.toHaveProperty('description');
    });

    it('conceals stale history totals after a ticket mutation until refreshed', async () => {
        let version = 4;
        vi.mocked(axios.get).mockImplementation(async (_url, config) => {
            const response = taskHistoryResponse({
                nonce: config?.params.review_nonce,
                taskId: 10,
                version,
            });
            const entries =
                version === 4
                    ? [taskCompletionEntry(1)]
                    : [taskCompletionEntry(2), taskCompletionEntry(1)];
            return {
                ...response,
                data: {
                    ...response.data,
                    data: {
                        ...response.data.data,
                        current_completion_id: version === 4 ? 101 : 102,
                        history: {
                            entries,
                            total_count: entries.length,
                            has_more: false,
                            next_before_sequence: null,
                        },
                    },
                },
            };
        });
        const page = render(<TicketWorkTasks {...props} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Completion history' }),
        );
        expect(
            await screen.findByText('Showing 1 of 1 recorded completions.'),
        ).toBeVisible();
        version = 5;
        page.rerender(<TicketWorkTasks {...props} version={5} />);
        expect(
            screen.queryByText('Showing 1 of 1 recorded completions.'),
        ).not.toBeInTheDocument();
        expect(screen.queryByText('Evidence note 1')).not.toBeInTheDocument();
        expect(
            screen.getByText(/The ticket changed. Refresh task history/),
        ).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh task history' }),
        );
        expect(
            await screen.findByText('Showing 2 of 2 recorded completions.'),
        ).toBeVisible();
        expect(screen.getByText('Evidence note 1')).toBeVisible();
        expect(screen.getByText('Evidence note 2')).toBeVisible();
    });

    it('shows unknown legacy evidence honestly and paginates without expanding history in the initial register', async () => {
        vi.mocked(axios.get).mockImplementation(async (_url, config) => {
            const sequence = config?.params.before_sequence ? 1 : 2;
            const entry = taskCompletionEntry(sequence);
            if (sequence === 1)
                Object.assign(entry, {
                    source: 'legacy_snapshot',
                    completed_at: null,
                    completed_by_user_id: null,
                    completed_by: null,
                    prerequisite_completions: null,
                    approval_id: null,
                    completion_note: 'Historical captured note',
                    evidence: null,
                });
            const response = taskHistoryResponse({
                nonce: config?.params.review_nonce,
                taskId: 10,
            });
            return {
                ...response,
                data: {
                    ...response.data,
                    data: {
                        ...response.data.data,
                        current_completion_id: 102,
                        readiness: taskReadiness({ completion: 'unknown' }),
                        history: {
                            entries: [entry],
                            total_count: 2,
                            has_more: sequence === 2,
                            next_before_sequence: sequence === 2 ? 2 : null,
                        },
                    },
                },
            };
        });
        render(<TicketWorkTasks {...props} />);
        expect(axios.get).not.toHaveBeenCalled();
        expect(screen.queryByText('Evidence note 2')).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Completion history' }),
        );
        await screen.findByText('Evidence note 2');
        fireEvent.click(
            screen.getByRole('button', { name: 'Load earlier completions' }),
        );
        await screen.findByText('Historical captured note');
        expect(screen.getByText(/Legacy evidence captured/)).toBeVisible();
        expect(
            screen.getByText(
                /Completed at an unrecorded time by an unrecorded person/,
            ),
        ).toBeVisible();
        expect(
            screen.getByText(
                'Prior prerequisite completions were not recorded.',
            ),
        ).toBeVisible();
        expect(
            screen.getByText(/Showing 2 of 2 recorded completions/),
        ).toBeVisible();
        expect(axios.request).not.toHaveBeenCalled();
    });

    it('purges visible private history on a confirmed access-loss refresh', async () => {
        const onAccessLost = vi.fn();
        render(<TicketWorkTasks {...props} onAccessLost={onAccessLost} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Completion history' }),
        );
        await screen.findByText(
            'No completion has been recorded for this task.',
        );
        vi.mocked(axios.get).mockRejectedValueOnce({
            isAxiosError: true,
            response: { status: 403 },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh task history' }),
        );
        await waitFor(() => expect(onAccessLost).toHaveBeenCalledOnce());
        expect(screen.queryByText(task.title)).not.toBeInTheDocument();
        expect(
            screen.queryByText(
                'No completion has been recorded for this task.',
            ),
        ).not.toBeInTheDocument();
    });

    it('keeps invalid completion evidence visible while requiring explicit reopen before repair', () => {
        render(
            <TicketWorkTasks
                {...props}
                tasks={[
                    {
                        ...task,
                        status: 'completed',
                        is_required: true,
                        readiness: taskReadiness({
                            completion: 'invalid',
                            can_complete: false,
                            can_edit: false,
                            can_reopen: true,
                            blockers: [
                                {
                                    code: 'dependency_changed',
                                    message:
                                        'A prerequisite changed after completion.',
                                    task_id: 11,
                                    approval_id: null,
                                },
                            ],
                        }),
                    },
                ]}
            />,
        );
        expect(
            screen.getByText('A prerequisite changed after completion.'),
        ).toBeVisible();
        expect(
            screen.getByRole('link', { name: 'Review task #11' }),
        ).toHaveAttribute('href', '/it/tickets/42?tab=tasks#task-11');
        expect(
            screen.getByRole('button', { name: 'Reopen task' }),
        ).toBeEnabled();
        expect(
            screen.queryByRole('button', { name: 'Complete task' }),
        ).not.toBeInTheDocument();
        expect(screen.getByText(/1 required outstanding/)).toBeVisible();
        expect(screen.getByText('0 of 1 verified complete')).toBeVisible();
        expect(screen.getByText('1 completion needs review')).toBeVisible();
    });

    it('labels legacy evidence as unverified without inventing a new required-work blocking policy', () => {
        render(
            <TicketWorkTasks
                {...props}
                tasks={[
                    {
                        ...task,
                        status: 'completed',
                        is_required: true,
                        readiness: taskReadiness({
                            completion: 'unknown',
                            can_complete: false,
                            can_edit: false,
                            can_reopen: true,
                        }),
                    },
                ]}
            />,
        );
        expect(screen.getByText('0 of 1 verified complete')).toBeVisible();
        expect(screen.getByText('1 completion unverified')).toBeVisible();
        expect(
            screen.queryByText(/required outstanding/),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText('Historical completion provenance is unknown.'),
        ).toBeVisible();
    });
});
