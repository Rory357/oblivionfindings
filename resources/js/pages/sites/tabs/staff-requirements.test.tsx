import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import {
    SiteProfileStaffRequirements,
    type SiteStaffRequirementsData,
} from './staff-requirements';
const transport = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
    reload: vi.fn(),
    delete: vi.fn(),
    on: vi.fn<
        (event: string, callback: (event: unknown) => void) => () => void
    >(() => () => {}),
    actorId: 4,
}));
vi.mock('@inertiajs/react', async () => {
    const React = await vi.importActual<typeof import('react')>('react');
    return {
        router: transport,
        usePage: () => ({
            props: { auth: { user: { id: transport.actorId } } },
        }),
        useForm: (initial: Record<string, unknown>) => {
            const [data, setData] = React.useState(initial);
            const [errors, setErrors] = React.useState<Record<string, string>>(
                {},
            );
            return {
                data,
                errors,
                processing: false,
                clearErrors: () => setErrors({}),
                setError: setErrors,
                setData: (
                    key: string | Record<string, unknown>,
                    value?: unknown,
                ) =>
                    typeof key === 'string'
                        ? setData((old) => ({ ...old, [key]: value }))
                        : setData(key),
                post: (url: string, options: unknown) =>
                    transport.post(url, data, options),
                put: (url: string, options: unknown) =>
                    transport.put(url, data, options),
            };
        },
    };
});
const data: SiteStaffRequirementsData = {
    locked: false,
    can_manage: true,
    items: [],
    house_qualification_approach: 'minimum_staff',
    new_requirement_defaults: {
        applicability_mode: 'minimum_staff',
        minimum_qualified_staff: null,
    },
    mapping_options: [
        {
            id: 12,
            name: 'First aid',
            code: 'FIRST_AID',
            check_type: 'credential',
        },
    ],
};
beforeEach(() => {
    vi.clearAllMocks();
    transport.actorId = 4;
});
it('uses the new House default while requiring the explicit count before a reviewable save', () => {
    render(<SiteProfileStaffRequirements siteId={5} data={data} />);
    fireEvent.click(
        screen.getAllByRole('button', { name: 'Add requirement' })[0],
    );
    fireEvent.change(screen.getByLabelText('Requirement name'), {
        target: { value: 'First aid cover' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(
        screen.getByLabelText('Who needs this qualification?'),
    ).toHaveTextContent('Minimum number');
    expect(screen.getByLabelText('Minimum qualified workers')).toHaveValue(
        null,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(
        screen.getByText('Enter a positive whole number of qualified workers.'),
    ).toBeVisible();
    expect(transport.post).not.toHaveBeenCalled();
    fireEvent.change(screen.getByLabelText('Minimum qualified workers'), {
        target: { value: '2' },
    });
    fireEvent.click(
        screen.getByRole('combobox', { name: 'Recognised qualification' }),
    );
    fireEvent.click(screen.getByRole('option', { name: /First aid/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(
        screen.getByText('At least 2 qualified workers throughout the duty'),
    ).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Save requirement' }));
    expect(transport.post.mock.calls[0][1]).toMatchObject({
        requirement_name: 'First aid cover',
        hr_compliance_requirement_id: 12,
        applicability_mode: 'minimum_staff',
        minimum_qualified_staff: 2,
    });
});

const existing = {
    id: 7,
    name: 'First aid',
    category: 'mandatory',
    description: 'Bring certificate',
    certification_required: true,
    applicability_mode: 'all_workers' as const,
    minimum_qualified_staff: null,
    hr_compliance_requirement_id: 12,
};
const editable = { ...data, items: [existing] };
function reviewEdit() {
    fireEvent.click(screen.getByRole('button', { name: 'Edit' }));
    fireEvent.change(screen.getByLabelText('Description'), {
        target: { value: ' Updated guidance ' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save requirement' }));
}
function savedPage(values: Record<string, unknown>, action = 'updated') {
    const { request_id, ...saved } = values;
    return {
        props: {
            auth: { user: { id: 4 } },
            flash: {
                house_qualification_result: {
                    version: 1,
                    scope: 'house_requirement',
                    actor_id: 4,
                    site_id: 5,
                    request_id,
                    action,
                    requirement_id: 7,
                    outcome: action === 'deleted' ? 'deleted' : 'saved',
                    changed: true,
                    values: action === 'deleted' ? null : saved,
                    refresh: {
                        source_type: 'site_staff_requirements',
                        source_id: 7,
                        source_fingerprint: 'a'.repeat(64),
                        source_version: 2,
                        site_id: 5,
                    },
                    committed_at: '2026-10-08T00:00:00.000Z',
                },
            },
        },
    };
}
it('keeps the editor open during a save and closes only for the exact confirmed values', () => {
    render(<SiteProfileStaffRequirements siteId={5} data={editable} />);
    reviewEdit();
    const [, values, callbacks] = transport.put.mock.calls[0];
    expect(values.description).toBe('Updated guidance');
    expect(screen.getByRole('button', { name: 'Saving…' })).toBeDisabled();
    act(() => {
        callbacks.onSuccess(savedPage(values));
        callbacks.onFinish();
    });
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(transport.put).toHaveBeenCalledTimes(1);
});
it('retains an unconfirmed draft and checks current records without resubmitting it', () => {
    render(<SiteProfileStaffRequirements siteId={5} data={editable} />);
    reviewEdit();
    const callbacks = transport.put.mock.calls[0][2];
    act(() => {
        callbacks.onSuccess({ props: {} });
        callbacks.onFinish();
    });
    expect(screen.getByRole('dialog')).toHaveTextContent(
        'Your entries are kept here',
    );
    expect(screen.getByRole('dialog')).toHaveTextContent('Updated guidance');
    fireEvent.click(
        screen.getByRole('button', { name: 'Check current requirements' }),
    );
    const reload = transport.reload.mock.calls[0][0];
    expect(reload.only).toEqual(['staffRequirementsData', 'site', 'auth']);
    act(() => {
        reload.onSuccess({
            props: {
                auth: { user: { id: 4 } },
                site: { id: 5 },
                staffRequirementsData: editable,
            },
        });
        reload.onFinish();
    });
    expect(screen.getByRole('dialog')).toHaveTextContent(
        'This does not confirm the earlier save',
    );
    expect(
        screen.queryByRole('button', { name: 'Save requirement' }),
    ).not.toBeInTheDocument();
    expect(transport.put).toHaveBeenCalledTimes(1);
});
it('retains the draft after a transport interruption and blocks a duplicate save', () => {
    render(<SiteProfileStaffRequirements siteId={5} data={editable} />);
    reviewEdit();
    const callbacks = transport.put.mock.calls[0][2];
    act(() => {
        callbacks.onCancel();
        callbacks.onFinish();
    });
    expect(screen.getByRole('dialog')).toHaveTextContent(
        'The result could not be confirmed',
    );
    expect(transport.put).toHaveBeenCalledTimes(1);
});
it('does not accept an old response after the actor changes', () => {
    const view = render(
        <SiteProfileStaffRequirements siteId={5} data={editable} />,
    );
    reviewEdit();
    const [, values, callbacks] = transport.put.mock.calls[0];
    transport.actorId = 8;
    view.rerender(<SiteProfileStaffRequirements siteId={5} data={editable} />);
    act(() => {
        callbacks.onSuccess(savedPage(values));
        callbacks.onFinish();
    });
    expect(screen.getByRole('dialog')).toHaveTextContent(
        'Your current access does not allow saving',
    );
    expect(
        screen.getByRole('button', { name: 'Check current requirements' }),
    ).toBeDisabled();
});
it('keeps deletion open until confirmed and sends only one request while busy', () => {
    render(<SiteProfileStaffRequirements siteId={5} data={editable} />);
    fireEvent.click(screen.getByRole('button', { name: 'Remove' }));
    fireEvent.click(screen.getByRole('button', { name: 'Remove requirement' }));
    expect(screen.getByRole('alertdialog')).toBeVisible();
    expect(
        screen.getByRole('button', { name: 'Remove requirement' }),
    ).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Remove requirement' }));
    const [, callbacks] = transport.delete.mock.calls[0];
    act(() => {
        callbacks.onSuccess(savedPage(callbacks.data, 'deleted'));
        callbacks.onFinish();
    });
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
    expect(transport.delete).toHaveBeenCalledTimes(1);
});
it('does not automatically repeat an unconfirmed removal', () => {
    render(<SiteProfileStaffRequirements siteId={5} data={editable} />);
    fireEvent.click(screen.getByRole('button', { name: 'Remove' }));
    fireEvent.click(screen.getByRole('button', { name: 'Remove requirement' }));
    const [, callbacks] = transport.delete.mock.calls[0];
    act(() => {
        callbacks.onFinish();
    });
    expect(screen.getByRole('alertdialog')).toHaveTextContent(
        'The result could not be confirmed',
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Check current requirements' }),
    );
    expect(transport.delete).toHaveBeenCalledTimes(1);
    expect(transport.reload).toHaveBeenCalledTimes(1);
});
it('asks before discarding changed entries', () => {
    render(<SiteProfileStaffRequirements siteId={5} data={editable} />);
    fireEvent.click(screen.getByRole('button', { name: 'Edit' }));
    fireEvent.change(screen.getByLabelText('Description'), {
        target: { value: 'Unsaved guidance' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(screen.getByRole('alertdialog')).toHaveTextContent(
        'Discard your changes?',
    );
    fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
    expect(screen.getByLabelText('Description')).toHaveValue(
        'Unsaved guidance',
    );
});
it('retains changed entries when a page navigation is attempted', () => {
    render(<SiteProfileStaffRequirements siteId={5} data={editable} />);
    fireEvent.click(screen.getByRole('button', { name: 'Edit' }));
    fireEvent.change(screen.getByLabelText('Description'), {
        target: { value: 'Unsaved guidance' },
    });
    const preventDefault = vi.fn();
    const listener = transport.on.mock.calls.at(-1)![1];
    act(() =>
        listener({
            detail: {
                visit: {
                    method: 'get',
                    only: [],
                    url: new URL('https://example.test/sites'),
                },
            },
            preventDefault,
        }),
    );
    expect(preventDefault).toHaveBeenCalledTimes(1);
    expect(screen.getByLabelText('Description')).toHaveValue(
        'Unsaved guidance',
    );
    expect(screen.getByRole('dialog')).toHaveTextContent(
        'Finish or close this form before leaving',
    );
});

it('does not replace an existing every-worker requirement with a new organisation default', () => {
    render(
        <SiteProfileStaffRequirements
            siteId={5}
            data={{
                ...data,
                items: [
                    {
                        id: 7,
                        name: 'First aid',
                        category: 'mandatory',
                        certification_required: true,
                        applicability_mode: 'all_workers',
                        minimum_qualified_staff: null,
                        hr_compliance_requirement_id: 12,
                    },
                ],
            }}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Edit' }));
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(
        screen.getByLabelText('Who needs this qualification?'),
    ).toHaveTextContent('Every worker');
    expect(
        screen.queryByLabelText('Minimum qualified workers'),
    ).not.toBeInTheDocument();
    expect(transport.put).not.toHaveBeenCalled();
});
it('shows unresolved coverage honestly without exposing write actions to a reader', () => {
    render(
        <SiteProfileStaffRequirements
            siteId={5}
            data={{
                ...data,
                can_manage: false,
                items: [
                    {
                        id: 7,
                        name: 'First aid',
                        category: 'mandatory',
                        certification_required: true,
                        applicability_mode: null,
                        minimum_qualified_staff: null,
                    },
                ],
            }}
        />,
    );
    expect(
        screen.getByText('Who needs this qualification has not been set'),
    ).toBeVisible();
    expect(
        screen.queryByRole('button', { name: 'Edit' }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Add requirement' }),
    ).not.toBeInTheDocument();
});
