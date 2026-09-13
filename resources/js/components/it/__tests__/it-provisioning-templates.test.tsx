import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import {
    ItProvisioningTemplates,
    type ProvisioningTemplate,
} from '../it-provisioning-templates';

const state = vi.hoisted(() => ({
    post: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
    errors: {} as Record<string, string>,
    flash: { success: 'Template saved.' } as {
        success?: string;
        error?: string;
    },
}));
vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');
    return {
        router: {
            on: vi.fn(() => () => {}),
            reload: state.reload,
            visit: state.visit,
        },
        useForm: (initial: Record<string, unknown>) => {
            const [data, setData] = useState(initial);
            const [errors, setErrors] = useState<Record<string, string>>({});
            const submit = (
                url: string,
                options: {
                    onSuccess: (page: {
                        props: Record<string, unknown>;
                    }) => void;
                    onError: (errors: Record<string, string>) => void;
                    onFinish: () => void;
                },
            ) => {
                state.post(url, data);
                if (Object.keys(state.errors).length) {
                    setErrors(state.errors);
                    options.onError(state.errors);
                } else options.onSuccess({ props: { flash: state.flash } });
                options.onFinish();
            };
            return {
                data,
                errors,
                processing: false,
                setData: (
                    key: string | Record<string, unknown>,
                    value?: unknown,
                ) =>
                    setData((previous) =>
                        typeof key === 'string'
                            ? { ...previous, [key]: value }
                            : key,
                    ),
                clearErrors: (...fields: string[]) =>
                    setErrors((previous) =>
                        fields.length
                            ? Object.fromEntries(
                                  Object.entries(previous).filter(
                                      ([field]) => !fields.includes(field),
                                  ),
                              )
                            : {},
                    ),
                setError: (key: string, message: string) =>
                    setErrors((previous) => ({ ...previous, [key]: message })),
                post: submit,
                patch: submit,
            };
        },
    };
});

const template: ProvisioningTemplate = {
    id: 7,
    lock_version: 3,
    name: 'Support worker joiner',
    description: 'Approved account workflow',
    lifecycle_type: 'joiner',
    position_role: null,
    site_id: 21,
    site: { id: 21, name: 'Approved house' },
    employment_type: null,
    selection_priority: 10,
    is_active: true,
    tasks: [
        {
            task_key: 'account',
            title: 'Prepare the account',
            description: 'Record manual verification evidence.',
            category: 'account',
            action: 'grant',
            request_type: 'account',
            responsible_team_id: 5,
            responsible_team: { id: 5, name: 'Service desk' },
            stage: 1,
            sort_order: 1,
            dependency_task_keys: [],
            trigger_fields: [],
            approval_required: true,
            evidence_required: true,
            due_offset_days: -1,
            fulfiller_fields: ['work_email'],
        },
    ],
};
const mount = () =>
    render(
        <ItProvisioningTemplates
            actorId={8}
            templates={[template]}
            teams={[{ id: 5, name: 'Service desk' }]}
            sites={[{ id: 21, name: 'Approved house' }]}
            positionRoles={['support_worker']}
        />,
    );
const edit = () => {
    mount();
    fireEvent.click(
        screen.getByRole('button', { name: 'Edit Support worker joiner' }),
    );
};
const review = () =>
    fireEvent.click(
        screen.getByRole('button', {
            name: /Review changes.*Confirm the new version/,
        }),
    );

beforeEach(() => {
    sessionStorage.clear();
    state.post.mockClear();
    state.reload.mockClear();
    state.visit.mockClear();
    state.errors = {};
    state.flash = { success: 'Template saved.' };
});
afterEach(() => vi.restoreAllMocks());

const newDraft = () => {
    mount();
    fireEvent.click(screen.getByRole('button', { name: 'New template' }));
    fireEvent.change(screen.getByRole('textbox', { name: 'Template name' }), {
        target: { value: 'Recoverable private template' },
    });
    fireEvent.click(
        screen.getByRole('button', { name: /Workflow steps.*Ownership/ }),
    );
    fireEvent.change(screen.getByRole('textbox', { name: 'Title' }), {
        target: { value: 'Manual account verification' },
    });
    review();
};

it('recovers an uncertain template create without posting a duplicate or retaining private fields in storage', async () => {
    let originalIdentity = '';
    const transport = vi
        .spyOn(axios, 'post')
        .mockImplementation(async (url, data) => {
            if (url === '/it/setup/provisioning-templates') {
                originalIdentity = (data as { request_uuid: string })
                    .request_uuid;
                throw new Error('Synthetic lost response');
            }
            return {
                status: 200,
                data: {
                    status: 'committed',
                    data: {
                        viewer_user_id: 8,
                        resource: 'provisioning-templates',
                        request_uuid: originalIdentity,
                        id: 25,
                        configuration_version: 'a'.repeat(64),
                        committed_configuration_version: 'a'.repeat(64),
                        replayed: true,
                    },
                },
            };
        });
    newDraft();
    fireEvent.click(screen.getByRole('button', { name: 'Save template' }));
    await waitFor(() =>
        expect(
            screen.getByRole('button', { name: 'Check saved result' }),
        ).toBeVisible(),
    );
    expect(
        screen.getByRole('button', { name: 'Save template' }),
    ).toBeDisabled();
    expect(
        screen.queryByRole('heading', { name: 'Template saved' }),
    ).not.toBeInTheDocument();
    expect(
        sessionStorage.getItem(
            'it.setup.pending-command.v1.actor.8.provisioning-templates',
        ),
    ).toBe(originalIdentity);
    expect(JSON.stringify(sessionStorage)).not.toContain(
        'Recoverable private template',
    );
    expect(JSON.stringify(sessionStorage)).not.toContain(
        'Manual account verification',
    );
    fireEvent.click(screen.getByRole('button', { name: 'Check saved result' }));
    await waitFor(() =>
        expect(
            screen.getByRole('heading', { name: 'Template saved' }),
        ).toBeVisible(),
    );
    expect(transport).toHaveBeenCalledTimes(2);
    expect(transport.mock.calls[1][0]).toBe(
        `/it/setup/commands/${originalIdentity}/recover`,
    );
    expect(transport.mock.calls[1][1]).toEqual({
        actor_user_id: 8,
        resource: 'provisioning-templates',
    });
    expect(state.post).not.toHaveBeenCalled();
    expect(
        sessionStorage.getItem(
            'it.setup.pending-command.v1.actor.8.provisioning-templates',
        ),
    ).toBeNull();
});

it.each(['footer', 'escape'] as const)(
    'conceals denied context and exits through refreshed permissions (%s)',
    async (exit) => {
        vi.spyOn(axios, 'post')
            .mockRejectedValueOnce(new Error('Synthetic lost response'))
            .mockResolvedValueOnce({ status: 404, data: {} });
        newDraft();
        fireEvent.click(screen.getByRole('button', { name: 'Save template' }));
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Check saved result' }),
            ).toBeVisible(),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Check saved result' }),
        );
        await waitFor(() =>
            expect(screen.getByText(/This draft is concealed/)).toBeVisible(),
        );
        expect(
            screen.queryByText('Recoverable private template'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('heading', { name: 'Template saved' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('heading', { name: 'Support worker joiner' }),
        ).not.toBeInTheDocument();
        if (exit === 'footer') {
            fireEvent.click(
                screen.getByRole('button', { name: 'Return to service desk' }),
            );
        } else {
            fireEvent.keyDown(document, { key: 'Escape' });
        }
        await waitFor(() => expect(state.visit).toHaveBeenCalledWith('/it'));
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
    },
);

it('keeps expired private context concealed while a recovery check is pending', async () => {
    let finish!: (value: {
        status: number;
        data: Record<string, unknown>;
    }) => void;
    vi.spyOn(axios, 'post')
        .mockResolvedValueOnce({ status: 419, data: {} })
        .mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
    newDraft();
    fireEvent.click(screen.getByRole('button', { name: 'Save template' }));
    await waitFor(() =>
        expect(screen.getByText(/This draft is concealed/)).toBeVisible(),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Check saved result' }));
    await waitFor(() =>
        expect(
            screen.getByRole('button', { name: 'Cancel wait' }),
        ).toBeVisible(),
    );
    expect(
        screen.queryByText('Recoverable private template'),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('heading', { name: 'Support worker joiner' }),
    ).not.toBeInTheDocument();
    await act(async () => finish({ status: 404, data: {} }));
    expect(screen.getByText(/This draft is concealed/)).toBeVisible();
});

it('requires explicit cancellation of an uncertain create before another save is enabled', async () => {
    let originalIdentity = '';
    const transport = vi
        .spyOn(axios, 'post')
        .mockImplementation(async (url, data) => {
            if (url === '/it/setup/provisioning-templates') {
                originalIdentity = (data as { request_uuid: string })
                    .request_uuid;
                throw new Error('Synthetic lost response');
            }
            return {
                status: 200,
                data: {
                    status: 'cancelled',
                    data: {
                        viewer_user_id: 8,
                        resource: 'provisioning-templates',
                        request_uuid: originalIdentity,
                        cancelled: true,
                    },
                },
            };
        });
    newDraft();
    fireEvent.click(screen.getByRole('button', { name: 'Save template' }));
    await waitFor(() =>
        expect(
            screen.getByRole('button', { name: 'Cancel earlier create' }),
        ).toBeVisible(),
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Cancel earlier create' }),
    );
    expect(transport).toHaveBeenCalledTimes(1);
    fireEvent.click(
        screen.getByRole('button', { name: 'Check and cancel create' }),
    );
    await waitFor(() =>
        expect(
            screen.getByRole('button', { name: 'Save template' }),
        ).toBeEnabled(),
    );
    expect(transport).toHaveBeenCalledTimes(2);
    expect(transport.mock.calls[1][0]).toBe(
        `/it/setup/commands/${originalIdentity}/cancel`,
    );
    expect(screen.getByText('Recoverable private template')).toBeVisible();
    expect(
        screen.queryByRole('heading', { name: 'Template saved' }),
    ).not.toBeInTheDocument();
    expect(
        sessionStorage.getItem(
            'it.setup.pending-command.v1.actor.8.provisioning-templates',
        ),
    ).toBeNull();
});

it('places create validation errors on the editable workflow step', async () => {
    vi.spyOn(axios, 'post').mockResolvedValue({
        status: 422,
        data: { errors: { 'tasks.0.title': ['Review this step title.'] } },
    });
    newDraft();
    fireEvent.click(screen.getByRole('button', { name: 'Save template' }));
    await waitFor(() =>
        expect(
            screen.getByRole('form', { name: 'Workflow steps' }),
        ).toHaveFocus(),
    );
    expect(screen.getByRole('alert')).toHaveTextContent(
        'Review this step title.',
    );
    expect(screen.getByRole('textbox', { name: 'Title' })).toBeEnabled();
    expect(
        screen.queryByRole('heading', { name: 'Template saved' }),
    ).not.toBeInTheDocument();
});

it('reviews matching rules and task evidence then saves with the original edit version', () => {
    edit();
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(screen.getByRole('form', { name: 'Workflow steps' })).toHaveFocus();
    expect(state.post).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    const dialog = screen.getByRole('dialog', {
        name: 'Edit lifecycle template',
    });
    expect(within(dialog).getByText('Save as version 4')).toBeVisible();
    expect(
        within(dialog).getByText('Required before completion'),
    ).toBeVisible();
    expect(
        within(dialog).getByText('Record manual verification evidence.'),
    ).toBeVisible();
    expect(state.post).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Save template' }));
    expect(state.post).toHaveBeenCalledWith(
        '/it/setup/provisioning-templates/7',
        expect.objectContaining({ expected_version: 3 }),
    );
    expect(
        screen.getByRole('heading', { name: 'Template saved' }),
    ).toBeVisible();
});

it.each([{ error: 'Save rejected.' }, {}])(
    'does not show success for an unconfirmed save (%j)',
    (flash) => {
        state.flash = flash;
        edit();
        review();
        fireEvent.click(screen.getByRole('button', { name: 'Save template' }));
        expect(
            screen.queryByRole('heading', { name: 'Template saved' }),
        ).not.toBeInTheDocument();
        expect(screen.getByRole('alert')).toHaveTextContent(
            flash.error ?? 'not confirmed saved',
        );
    },
);

it('preserves a stale draft until the author confirms loading the current version', () => {
    state.errors = {
        expected_version:
            'This template has changed. Reload the saved version.',
    };
    edit();
    fireEvent.change(screen.getByRole('textbox', { name: 'Template name' }), {
        target: { value: 'My unsaved changes' },
    });
    review();
    fireEvent.click(screen.getByRole('button', { name: 'Save template' }));
    expect(screen.getByText('My unsaved changes')).toBeVisible();
    fireEvent.click(
        screen.getByRole('button', { name: 'Reload saved version' }),
    );
    expect(state.reload).not.toHaveBeenCalled();
    fireEvent.click(
        within(screen.getByRole('alertdialog')).getByRole('button', {
            name: 'Cancel',
        }),
    );
    expect(screen.getByText('My unsaved changes')).toBeVisible();
    fireEvent.click(
        screen.getByRole('button', { name: 'Reload saved version' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Discard changes' }));
    expect(state.reload).toHaveBeenCalledWith(
        expect.objectContaining({ only: ['provisioningTemplates'] }),
    );
    expect(state.post).toHaveBeenCalledTimes(1);
});

it('keeps unsaved edits when closing is cancelled and never saves through the discard control', () => {
    edit();
    fireEvent.change(screen.getByRole('textbox', { name: 'Template name' }), {
        target: { value: 'Unsaved name' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    fireEvent.click(
        within(screen.getByRole('alertdialog')).getByRole('button', {
            name: 'Cancel',
        }),
    );
    expect(screen.getByRole('textbox', { name: 'Template name' })).toHaveValue(
        'Unsaved name',
    );
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    fireEvent.click(screen.getByRole('button', { name: 'Discard changes' }));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(state.post).not.toHaveBeenCalled();
});

it('returns invalid required details to their editable step', () => {
    edit();
    fireEvent.change(screen.getByRole('textbox', { name: 'Template name' }), {
        target: { value: '' },
    });
    review();
    fireEvent.click(screen.getByRole('button', { name: 'Save template' }));
    expect(
        screen.getByRole('form', { name: 'Template details' }),
    ).toHaveFocus();
    expect(screen.getByRole('alert')).toHaveTextContent(
        'Enter a template name.',
    );
    expect(state.post).not.toHaveBeenCalled();
    fireEvent.change(screen.getByRole('textbox', { name: 'Template name' }), {
        target: { value: 'Corrected template name' },
    });
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    expect(state.post).not.toHaveBeenCalled();
});

it('returns a server task-field error to the workflow step without a success message', () => {
    state.errors = { 'tasks.0.title': 'Enter a valid workflow step title.' };
    edit();
    review();
    fireEvent.click(screen.getByRole('button', { name: 'Save template' }));
    expect(screen.getByRole('form', { name: 'Workflow steps' })).toHaveFocus();
    expect(screen.getByRole('alert')).toHaveTextContent(
        'Enter a valid workflow step title.',
    );
    expect(
        screen.queryByRole('heading', { name: 'Template saved' }),
    ).not.toBeInTheDocument();
});

it('distinguishes an unavailable saved team from an unassigned step during review', () => {
    render(
        <ItProvisioningTemplates
            templates={[template]}
            teams={[]}
            sites={[{ id: 21, name: 'Approved house' }]}
            positionRoles={[]}
        />,
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Edit Support worker joiner' }),
    );
    review();
    expect(
        screen.getByText('Unavailable team — choose an active team'),
    ).toBeVisible();
    expect(state.post).not.toHaveBeenCalled();
});
