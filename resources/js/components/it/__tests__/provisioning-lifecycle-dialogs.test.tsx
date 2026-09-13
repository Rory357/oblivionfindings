import { router } from '@inertiajs/react';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axios, { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { ProvisioningBulkDialog } from '../provisioning-bulk-dialog';
import { ProvisioningCommandDialog } from '../provisioning-command-dialog';
import { ProvisioningLaunchDialog } from '../provisioning-launch-dialog';
import { type ProvisioningTask } from '../provisioning-workspace';
import { provisioningBulkStorageKey } from '../use-provisioning-bulk-command';

vi.mock('@inertiajs/react', () => ({
    router: { on: vi.fn(() => () => {}), visit: vi.fn(), reload: vi.fn() },
    Link: ({
        children,
        href,
        ...props
    }: React.AnchorHTMLAttributes<HTMLAnchorElement>) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));

const employee = { id: 51, label: 'Synthetic employee · EMP-51' };
const owner = { id: 7, label: 'Synthetic accountable owner' };
const cover = { id: 8, label: 'Synthetic absence cover' };
const template = {
    id: 41,
    label: 'Reviewed joiner · Version 2',
    lifecycle_type: 'joiner',
    tasks: [
        {
            task_key: 'account',
            title: 'Create reviewed account',
            description: 'Private original account instructions',
            action: 'grant',
            stage: 1,
            dependency_task_keys: [],
            approval_required: true,
            evidence_required: true,
            due_offset_days: -2,
        },
        {
            task_key: 'verify',
            title: 'Verify staff access',
            description: 'Private downstream verification',
            action: 'verify',
            stage: 2,
            dependency_task_keys: ['account'],
            approval_required: false,
            evidence_required: true,
            due_offset_days: 1,
        },
    ],
};
function choices(
    params: Record<string, unknown>,
    options: object[],
    selected: object | null,
) {
    return {
        data: {
            actor_user_id: params.actor_user_id,
            kind: params.kind,
            context_kind: params.context_kind ?? null,
            context_id: params.context_id ?? null,
            page: params.page,
            has_more: false,
            options,
            selected,
        },
    };
}
function mockChoices() {
    return vi.spyOn(axios, 'get').mockImplementation(async (_url, config) => {
        const params = config?.params as Record<string, unknown>;
        const options =
            params.kind === 'employees'
                ? [employee]
                : params.kind === 'templates'
                  ? [template]
                  : [owner, cover];
        return choices(
            params,
            options,
            options.find((row) => row.id === params.selected_id) ?? null,
        );
    });
}
async function openChoices(label: string) {
    // Let Radix finish focus restoration from the previous dialog/popover
    // before opening the next one, as it would between user interactions.
    await act(async () => {
        await new Promise((resolve) => setTimeout(resolve, 0));
    });
    fireEvent.click(screen.getByRole('combobox', { name: label }));
}
async function choose(label: string, name: string) {
    await openChoices(label);
    fireEvent.click(await screen.findByRole('option', { name }));
    await waitFor(() =>
        expect(screen.getByRole('combobox', { name: label })).toHaveTextContent(
            name,
        ),
    );
}
function saved(
    body: Record<string, unknown>,
    id: number,
    kind = 'request',
    target = id,
    operation = 'retry',
    status = 'committed',
) {
    return {
        data: {
            status,
            data: {
                viewer_user_id: body.actor_user_id,
                request_uuid: body.request_uuid,
                kind,
                target_id: target,
                operation,
                ...(status === 'committed'
                    ? {
                          result_id: id,
                          lock_version: 6,
                          replayed: false,
                          url:
                              '/it/provisioning/' +
                              (kind === 'launch' ? 'workflows/' : 'tasks/') +
                              id,
                      }
                    : {}),
            },
        },
    };
}
function task(id: number): ProvisioningTask {
    return {
        id,
        version: id === 12 ? 4 : 5,
        reference: 'IT-P' + id,
        title: 'Private original task ' + id,
        href: '/it/provisioning/tasks/' + id,
        status: 'failed',
        employee: { id: 51, name: 'Synthetic employee' },
        workflow: null,
        type: 'other',
        category: 'other',
        action: 'change',
        stage: 1,
        due_date: null,
        priority: 'normal',
        approval_required: false,
        approval_status: 'not_required',
        evidence_required: true,
        reversal_of_request_id: null,
        assignee: null,
        team: null,
        readiness: {
            storage_ready: true,
            actions: ['retry', 'cancel'],
            blockers: [],
            next_action: 'Review the failure',
            worker: { id: owner.id, name: owner.label },
            approver: null,
            manual_evidence: true,
        },
    };
}
beforeEach(() => sessionStorage.clear());
afterEach(() => {
    vi.restoreAllMocks();
    vi.clearAllMocks();
});

it('reviews published instructions and recovers a lost launch response with its original command identity', async () => {
    const get = mockChoices();
    const post = vi
        .spyOn(axios, 'post')
        .mockRejectedValueOnce(new Error('Lost response'));
    const close = vi.fn();
    render(
        <ProvisioningLaunchDialog
            actorId={3}
            onClose={close}
            onDenied={vi.fn()}
        />,
    );
    await choose('Employee', employee.label);
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    await choose('Published workflow template', template.label);
    fireEvent.change(screen.getByLabelText('Effective date *'), {
        target: { value: '2026-09-18' },
    });
    await choose('Workflow owner', owner.label);
    await choose('Absence cover', cover.label);
    fireEvent.change(
        screen.getByRole('textbox', {
            name: 'Reason for starting this workflow *',
        }),
        { target: { value: 'Private launch justification' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(await screen.findByText('Published task preview')).toBeVisible();
    expect(screen.getByText('Stage 1: Create reviewed account')).toBeVisible();
    expect(screen.getByText('After: Create reviewed account')).toBeVisible();
    expect(
        screen.getByText('Due 2 days before the effective date'),
    ).toBeVisible();
    expect(
        screen.getByText(/Grant · Approval required · Evidence required/),
    ).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Start workflow' }));
    await screen.findByRole('button', { name: 'Check saved outcome' });
    expect(post).toHaveBeenCalledOnce();
    const body = post.mock.calls[0]?.[1] as Record<string, unknown>;
    expect(body).toMatchObject({
        actor_user_id: 3,
        template_version_id: 41,
        lifecycle_type: 'joiner',
        effective_date: '2026-09-18',
        owner_user_id: 7,
        cover_user_id: 8,
        reason: 'Private launch justification',
    });
    expect(JSON.stringify(sessionStorage)).not.toContain('Private');
    get.mockResolvedValueOnce(saved(body, 91, 'launch', 51, 'launch'));
    fireEvent.click(
        screen.getByRole('button', { name: 'Check saved outcome' }),
    );
    await screen.findByText('Work updated');
    expect(post).toHaveBeenCalledOnce();
    expect(get).toHaveBeenLastCalledWith(
        '/it/provisioning/commands/launch/51/launch',
        expect.objectContaining({
            params: expect.objectContaining({
                request_uuid: body.request_uuid,
            }),
        }),
    );
    fireEvent.click(screen.getByText('Close', { selector: 'button' }));
    expect(close).toHaveBeenCalledOnce();
    expect(router.reload).toHaveBeenCalledOnce();
});

it('blocks a retained required picker value when the original choice is no longer available', async () => {
    vi.spyOn(axios, 'get').mockImplementation(async (_url, config) =>
        choices(config?.params, [], null),
    );
    const post = vi.spyOn(axios, 'post');
    render(
        <ProvisioningCommandDialog
            context={{
                actorId: 3,
                kind: 'request',
                targetId: 12,
                operation: 'assign',
            }}
            version={4}
            title="Assign this task"
            description="Review current responsibility"
            review={[]}
            fields={[
                {
                    key: 'assigned_to_user_id',
                    label: 'Task assignee',
                    kind: 'agent',
                    required: true,
                },
            ]}
            initial={{ assigned_to_user_id: 7 }}
            onClose={vi.fn()}
            onDenied={vi.fn()}
        />,
    );
    await waitFor(() =>
        expect(
            screen.getByRole('combobox', { name: 'Task assignee' }),
        ).toHaveTextContent('Previous choice unavailable'),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(screen.getByText('Choose or enter task assignee.')).toBeVisible();
    expect(screen.queryByText('Review the change')).not.toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
});

it('rejects a template option without the published task contract', async () => {
    const get = mockChoices();
    render(
        <ProvisioningLaunchDialog
            actorId={3}
            onClose={vi.fn()}
            onDenied={vi.fn()}
        />,
    );
    await choose('Employee', employee.label);
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    get.mockImplementation(async (_url, config) =>
        choices(
            config?.params,
            [{ id: 41, label: template.label, lifecycle_type: 'joiner' }],
            null,
        ),
    );
    await openChoices('Published workflow template');
    expect(
        await screen.findByText(
            'Choices could not be checked. Retry before selecting.',
        ),
    ).toBeVisible();
    expect(
        screen.queryByRole('option', { name: template.label }),
    ).not.toBeInTheDocument();
});

it('hides private fields when checking a picker discovers lost access', async () => {
    const denied = vi.fn();
    vi.spyOn(axios, 'get').mockRejectedValue(
        new AxiosError(
            'Synthetic expired session',
            undefined,
            undefined,
            undefined,
            {
                status: 401,
                statusText: 'Unauthenticated',
                data: {},
                headers: {},
                config: { headers: {} } as never,
            },
        ),
    );
    render(
        <ProvisioningCommandDialog
            context={{
                actorId: 3,
                kind: 'request',
                targetId: 12,
                operation: 'assign',
            }}
            version={4}
            title="Assign this task"
            description="Review current responsibility"
            review={[]}
            fields={[
                {
                    key: 'assigned_to_user_id',
                    label: 'Task assignee',
                    kind: 'agent',
                    required: true,
                },
                { key: 'reason', label: 'Reason', kind: 'textarea' },
            ]}
            initial={{ reason: 'Private explanation for assignment' }}
            onClose={vi.fn()}
            onDenied={denied}
        />,
    );
    fireEvent.click(screen.getByRole('combobox', { name: 'Task assignee' }));
    await waitFor(() => expect(denied).toHaveBeenCalledOnce());
    expect(
        screen.queryByDisplayValue('Private explanation for assignment'),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Continue' }),
    ).not.toBeInTheDocument();
});

it('shows separate bulk outcomes and recovers only the unknown original row', async () => {
    const post = vi
        .spyOn(axios, 'post')
        .mockImplementation(async (url, value) => {
            if (String(url).includes('/13/'))
                throw new Error('Lost second response');
            return saved(value as Record<string, unknown>, 12);
        });
    const get = vi.spyOn(axios, 'get');
    render(
        <ProvisioningBulkDialog
            actorId={3}
            selection={{ operation: 'retry', tasks: [task(12), task(13)] }}
            onClose={vi.fn()}
            onDenied={vi.fn()}
        />,
    );
    expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
    fireEvent.change(
        screen.getByRole('textbox', { name: 'Reason and next action *' }),
        { target: { value: 'Private reviewed retry reason' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(
        screen.getByText('Synthetic employee · Private original task 12'),
    ).toBeVisible();
    expect(
        screen.getByText('Synthetic employee · Private original task 13'),
    ).toBeVisible();
    fireEvent.click(
        screen.getByRole('button', { name: 'Confirm 2 task changes' }),
    );
    const first = await screen.findByRole('region', {
        name: 'Result for task 12',
    });
    const second = await screen.findByRole('region', {
        name: 'Result for task 13',
    });
    await waitFor(() =>
        expect(
            within(second).getByRole('button', { name: 'Check outcome' }),
        ).toBeEnabled(),
    );
    expect(
        within(first).queryByRole('button', { name: 'Check outcome' }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByText('Every original command is accounted for.'),
    ).not.toBeInTheDocument();
    const body = post.mock.calls[1]?.[1] as Record<string, unknown>;
    expect(JSON.stringify(sessionStorage)).not.toContain('Private');
    get.mockResolvedValueOnce(saved(body, 13));
    fireEvent.click(
        within(second).getByRole('button', { name: 'Check outcome' }),
    );
    await screen.findByText('Every original command is accounted for.');
    expect(post).toHaveBeenCalledTimes(2);
    expect(get).toHaveBeenCalledOnce();
    expect(sessionStorage.getItem(provisioningBulkStorageKey(3))).toBeNull();
});

it('restores bulk identities without restoring private form data or automatically repeating a change', async () => {
    sessionStorage.setItem(
        provisioningBulkStorageKey(3),
        JSON.stringify({
            actorId: 3,
            operation: 'retry',
            items: [
                {
                    id: 12,
                    version: 4,
                    requestUuid: '72e8dcf5-9363-4c29-ae3b-f78a527e6263',
                },
            ],
        }),
    );
    const post = vi.spyOn(axios, 'post');
    const get = vi.spyOn(axios, 'get');
    render(
        <ProvisioningBulkDialog
            actorId={3}
            onClose={vi.fn()}
            onDenied={vi.fn()}
        />,
    );
    expect(
        screen.getByText(
            'This browser retained only the original task references. Check their outcomes before preparing new changes.',
        ),
    ).toBeVisible();
    expect(screen.getByRole('button', { name: 'Check outcome' })).toBeVisible();
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: /Confirm .*task changes/ }),
    ).not.toBeInTheDocument();
    await act(async () => {});
    expect(post).not.toHaveBeenCalled();
    expect(get).not.toHaveBeenCalled();
});
