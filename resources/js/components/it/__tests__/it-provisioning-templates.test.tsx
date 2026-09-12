import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
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
    state.post.mockClear();
    state.reload.mockClear();
    state.visit.mockClear();
    state.errors = {};
    state.flash = { success: 'Template saved.' };
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
