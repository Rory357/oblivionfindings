import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import {
    ItCatalogueManagement,
    type CatalogManagementItem,
} from '../it-catalogue-management';
import { ItServiceCatalogue } from '../it-service-catalogue';

const state = vi.hoisted(() => ({
    post: vi.fn(),
    errors: {} as Record<string, string>,
    processing: false,
    response: null as { success?: string; error?: string } | null,
}));
vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');
    return {
        router: { on: vi.fn(() => () => {}), visit: vi.fn() },
        useForm: (initial: Record<string, unknown>) => {
            const [data, setData] = useState(initial);
            const [errors, setErrors] = useState<Record<string, string>>({});
            const submit = (
                url: string,
                options?: {
                    onSuccess?: (page: {
                        props: Record<string, unknown>;
                    }) => void;
                    onFinish?: () => void;
                },
            ) => {
                state.post(url, data);
                if (state.response)
                    options?.onSuccess?.({ props: { flash: state.response } });
                options?.onFinish?.();
            };
            return {
                data,
                setData: (
                    key: string | Record<string, unknown>,
                    value?: unknown,
                ) =>
                    setData((previous) =>
                        typeof key === 'string'
                            ? { ...previous, [key]: value }
                            : key,
                    ),
                errors: { ...state.errors, ...errors },
                processing: state.processing,
                clearErrors: (...fields: string[]) =>
                    setErrors((previous) =>
                        fields.length === 0
                            ? {}
                            : Object.fromEntries(
                                  Object.entries(previous).filter(
                                      ([key]) => !fields.includes(key),
                                  ),
                              ),
                    ),
                setError: (
                    key: string | Record<string, string>,
                    message: string,
                ) =>
                    setErrors((previous) => ({
                        ...previous,
                        ...(typeof key === 'string' ? { [key]: message } : key),
                    })),
                reset: vi.fn(),
                transform: vi.fn(),
                patch: submit,
                post: submit,
            };
        },
    };
});

const item: CatalogManagementItem = {
    id: 12,
    name: 'Equipment request',
    slug: 'equipment',
    description: null,
    it_service_id: null,
    service_name: null,
    outcome_type: 'provisioning',
    category: 'hardware',
    provisioning_type: 'equipment',
    default_priority: 'normal',
    requires_approval: true,
    internal_only: false,
    is_published: true,
    form_schema_version: 3,
    published_version: 2,
    lock_version: 7,
    form_schema: { fields: [] },
    search_terms: [],
    sort_order: 0,
    submission_count: 4,
};

beforeEach(() => {
    sessionStorage.clear();
    state.post.mockClear();
    state.errors = {};
    state.processing = false;
    state.response = null;
});
afterEach(() => vi.restoreAllMocks());

it('recovers an uncertain catalogue create without sending a second draft', async () => {
    let originalIdentity = '';
    const transport = vi
        .spyOn(axios, 'post')
        .mockImplementation(async (url, data) => {
            if (url === '/it/setup/catalogue-items') {
                originalIdentity = (data as { request_uuid: string })
                    .request_uuid;
                throw new Error('Synthetic lost response');
            }
            return {
                status: 200,
                data: {
                    status: 'committed',
                    data: {
                        viewer_user_id: 3,
                        resource: 'catalogue-items',
                        request_uuid: originalIdentity,
                        id: 25,
                        configuration_version: 'a'.repeat(64),
                        committed_configuration_version: 'a'.repeat(64),
                        replayed: true,
                    },
                },
            };
        });
    render(<ItCatalogueManagement actorId={3} items={[]} services={[]} />);
    fireEvent.click(screen.getByRole('button', { name: 'New request form' }));
    fireEvent.change(screen.getByLabelText('Request name'), {
        target: { value: 'Recover this request' },
    });
    fireEvent.click(
        screen.getByRole('button', {
            name: /Review draft.*Check before saving/,
        }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
    await waitFor(() =>
        expect(
            screen.getByRole('button', { name: 'Check saved result' }),
        ).toBeVisible(),
    );
    expect(screen.getByRole('button', { name: 'Save draft' })).toBeDisabled();
    expect(
        screen.queryByRole('heading', { name: 'Draft saved' }),
    ).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Check saved result' }));
    await waitFor(() =>
        expect(
            screen.getByRole('heading', { name: 'Draft saved' }),
        ).toBeVisible(),
    );
    expect(transport).toHaveBeenCalledTimes(2);
    expect(transport.mock.calls[1][0]).toBe(
        `/it/setup/commands/${originalIdentity}/recover`,
    );
    expect(transport.mock.calls[1][1]).toEqual({
        actor_user_id: 3,
        resource: 'catalogue-items',
    });
    expect(
        sessionStorage.getItem(
            'it.setup.pending-command.v1.actor.3.catalogue-items',
        ),
    ).toBeNull();
});

it.each([
    {
        field: 'name',
        message: 'Use a shorter request name.',
        step: 'Request details',
    },
    {
        field: 'form_schema.fields.0.options',
        message: 'Use distinct choices.',
        step: 'Form fields',
    },
])(
    'returns server validation to the affected step: $field',
    async ({ field, message, step }) => {
        const transport = vi.spyOn(axios, 'post').mockResolvedValue({
            status: 422,
            data: { errors: { [field]: [message] } },
        });
        render(<ItCatalogueManagement actorId={3} items={[]} services={[]} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'New request form' }),
        );
        fireEvent.change(screen.getByLabelText('Request name'), {
            target: { value: 'Equipment request' },
        });
        fireEvent.click(
            screen.getByRole('button', {
                name: /Review draft.*Check before saving/,
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        await waitFor(() =>
            expect(screen.getByRole('heading', { name: step })).toBeVisible(),
        );
        expect(screen.getAllByText(message)).toHaveLength(1);
        expect(screen.getByRole('alert')).toHaveTextContent(message);
        if (field === 'name')
            expect(
                screen.getByRole('textbox', {
                    name: 'Request name',
                }),
            ).toHaveValue('Equipment request');
        expect(
            screen.queryByRole('heading', { name: 'Draft saved' }),
        ).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Continue' })).toBeEnabled();
        expect(transport).toHaveBeenCalledTimes(1);
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(
            within(
                screen.getByRole('form', {
                    name:
                        step === 'Request details'
                            ? 'Form fields'
                            : 'Review draft',
                }),
            ).queryByRole('alert'),
        ).not.toBeInTheDocument();
    },
);

it('keeps the live version visible while requiring review of a revised publication', () => {
    render(<ItCatalogueManagement actorId={3} items={[item]} services={[]} />);
    expect(screen.getByText('v2')).toBeVisible();
    expect(screen.getByText('v3')).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Review and publish' }));
    expect(state.post).not.toHaveBeenCalled();
    const review = screen.getByRole('dialog', { name: 'Publish request' });
    expect(within(review).getByText('Required')).toBeVisible();
    expect(within(review).getByText('Approved requesters')).toBeVisible();
    fireEvent.click(
        within(review).getByRole('button', { name: 'Publish request' }),
    );
    expect(state.post).toHaveBeenCalledWith(
        '/it/setup/catalogue-items/12/publish',
        { expected_version: 7 },
    );
});

it('shows a stale publication error and preserves the review instead of claiming success', () => {
    state.errors = {
        expected_version:
            'This request changed in another window. Reload and review the current draft.',
    };
    render(<ItCatalogueManagement actorId={3} items={[item]} services={[]} />);
    fireEvent.click(screen.getByRole('button', { name: 'Review and publish' }));
    const review = screen.getByRole('dialog', { name: 'Publish request' });
    expect(within(review).getByRole('alert')).toHaveTextContent(
        'changed in another window',
    );
    expect(state.post).not.toHaveBeenCalled();
});

it('offers withdrawal without offering a redundant publication for an unchanged live draft', () => {
    render(
        <ItCatalogueManagement
            actorId={3}
            items={[{ ...item, published_version: 3 }]}
            services={[]}
        />,
    );
    expect(
        screen.queryByRole('button', { name: 'Review and publish' }),
    ).not.toBeInTheDocument();
    expect(
        screen.getByRole('button', { name: 'Withdraw from catalogue' }),
    ).toBeVisible();
});

it('keeps an unavailable-service publication error visible in review', () => {
    state.errors = {
        publication: 'Choose an active service before publishing this request.',
    };
    render(<ItCatalogueManagement actorId={3} items={[item]} services={[]} />);
    fireEvent.click(screen.getByRole('button', { name: 'Review and publish' }));
    const review = screen.getByRole('dialog', { name: 'Publish request' });
    expect(within(review).getByRole('alert')).toHaveTextContent(
        'Choose an active service',
    );
    expect(state.post).not.toHaveBeenCalled();
});

it('uses the wizard review and keeps a dirty edit when discard is cancelled', () => {
    render(<ItCatalogueManagement actorId={3} items={[item]} services={[]} />);
    fireEvent.click(screen.getByRole('button', { name: 'Edit' }));
    const editor = screen.getByRole('dialog', {
        name: 'Edit Equipment request',
    });
    expect(
        within(editor).getByRole('button', {
            name: /Provisioning.*Request equipment/,
        }),
    ).toHaveAttribute('aria-pressed', 'true');
    fireEvent.change(within(editor).getByLabelText('Request name'), {
        target: { value: 'Revised equipment request' },
    });
    fireEvent.click(within(editor).getByRole('button', { name: 'Cancel' }));
    const discard = screen.getByRole('alertdialog');
    fireEvent.click(within(discard).getByRole('button', { name: 'Cancel' }));
    expect(within(editor).getByLabelText('Request name')).toHaveValue(
        'Revised equipment request',
    );
    fireEvent.click(within(editor).getByRole('button', { name: 'Continue' }));
    expect(
        within(editor).getByRole('heading', { name: 'Form fields' }),
    ).toBeVisible();
    const continueButton = within(editor).getByRole('button', {
        name: 'Continue',
    });
    expect(continueButton).toHaveAttribute('type', 'button');
    fireEvent.click(continueButton);
    // Native click default actions must not see this same element turn into
    // the form's submit button as React switches to the review step.
    expect(continueButton).not.toBeInTheDocument();
    expect(continueButton).not.toHaveAttribute('form');
    expect(
        within(editor).getByRole('heading', { name: 'Review draft' }),
    ).toBeVisible();
    expect(within(editor).getByText('Revised equipment request')).toBeVisible();
    expect(state.post).not.toHaveBeenCalled();
});

it('shows an unselected provisioning type until the author chooses one', () => {
    render(<ItCatalogueManagement actorId={3} items={[]} services={[]} />);
    fireEvent.click(screen.getByRole('button', { name: 'New request form' }));
    fireEvent.click(
        screen.getByRole('button', { name: /Provisioning.*Request equipment/ }),
    );
    const type = screen.getByLabelText('Provisioning type');
    expect(type).toHaveValue('');
    expect(
        within(type).getByRole('option', {
            name: 'Choose provisioning type',
            selected: true,
        }),
    ).toBeInTheDocument();
    fireEvent.change(type, { target: { value: 'equipment' } });
    expect(type).toHaveValue('equipment');
    expect(state.post).not.toHaveBeenCalled();
});

it.each([
    { response: { success: 'Draft saved.' }, saved: true },
    { response: { error: 'Save rejected.' }, saved: false },
])(
    'only shows draft success after a confirmed save: $saved',
    ({ response, saved }) => {
        state.response = response;
        render(
            <ItCatalogueManagement actorId={3} items={[item]} services={[]} />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Edit' }));
        const editor = screen.getByRole('dialog', {
            name: 'Edit Equipment request',
        });
        fireEvent.click(
            within(editor).getByRole('button', {
                name: /Review draft.*Check before saving/,
            }),
        );
        fireEvent.click(
            within(editor).getByRole('button', {
                name: 'Save draft',
            }),
        );
        expect(state.post).toHaveBeenCalledWith(
            '/it/setup/catalogue-items/12',
            expect.objectContaining({ expected_version: 7 }),
        );
        if (saved)
            expect(
                within(editor).getByRole('heading', { name: 'Draft saved' }),
            ).toBeVisible();
        else {
            expect(
                within(editor).queryByRole('heading', { name: 'Draft saved' }),
            ).not.toBeInTheDocument();
            expect(
                within(editor).getAllByText('Save rejected.').length,
            ).toBeGreaterThan(0);
        }
    },
);

it('requires an explicit selected Site and includes the audience in draft review and save', () => {
    render(
        <ItCatalogueManagement
            actorId={3}
            items={[item]}
            services={[]}
            sites={[{ id: 21, name: 'Approved house' }]}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Edit' }));
    fireEvent.change(screen.getByRole('combobox', { name: 'Available at' }), {
        target: { value: 'selected' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(screen.getByRole('alert')).toHaveTextContent(
        'Choose at least one approved site.',
    );
    expect(state.post).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('checkbox', { name: 'Approved house' }));
    fireEvent.click(screen.getByRole('checkbox', { name: 'IT staff only' }));
    fireEvent.click(
        screen.getByRole('button', {
            name: /Review draft.*Check before saving/,
        }),
    );
    expect(screen.getByText('Approved house')).toBeVisible();
    expect(screen.getByText('IT staff')).toBeVisible();
    expect(
        screen.queryByText('Choose at least one approved site.'),
    ).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
    expect(state.post).toHaveBeenCalledWith(
        '/it/setup/catalogue-items/12',
        expect.objectContaining({ site_scope: [21], internal_only: true }),
    );
});

it.each(['selected', 'all'])(
    'clears corrected Site errors while preserving unrelated validation (%s)',
    (scope) => {
        render(
            <ItCatalogueManagement
                actorId={3}
                items={[item]}
                services={[]}
                sites={[{ id: 21, name: 'Approved house' }]}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Edit' }));
        fireEvent.change(screen.getByLabelText('Request name'), {
            target: { value: '' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(screen.getByText('Enter a request name.')).toBeVisible();
        fireEvent.change(
            screen.getByRole('combobox', { name: 'Available at' }),
            {
                target: { value: 'selected' },
            },
        );
        expect(screen.getByText('Enter a request name.')).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(
            screen.getByText('Choose at least one approved site.'),
        ).toBeVisible();
        if (scope === 'selected') {
            fireEvent.click(
                screen.getByRole('checkbox', { name: 'Approved house' }),
            );
        } else {
            fireEvent.change(
                screen.getByRole('combobox', { name: 'Available at' }),
                {
                    target: { value: 'all' },
                },
            );
        }
        expect(
            screen.queryByText('Choose at least one approved site.'),
        ).not.toBeInTheDocument();
        expect(state.post).not.toHaveBeenCalled();
    },
);

it('lets an author remove an unavailable saved Site without concealing its restriction', () => {
    render(
        <ItCatalogueManagement
            actorId={3}
            items={[{ ...item, site_scope: [99, 21] }]}
            services={[]}
            sites={[{ id: 21, name: 'Approved house' }]}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Edit' }));
    fireEvent.click(
        screen.getByRole('checkbox', { name: 'Unavailable saved site' }),
    );
    expect(
        screen.queryByRole('checkbox', { name: 'Unavailable saved site' }),
    ).not.toBeInTheDocument();
    fireEvent.click(
        screen.getByRole('button', {
            name: /Review draft.*Check before saving/,
        }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
    expect(state.post).toHaveBeenCalledWith(
        '/it/setup/catalogue-items/12',
        expect.objectContaining({ site_scope: [21] }),
    );
});

it('shows approved request Sites and submits the staff-selected Site with the request', () => {
    render(
        <ItServiceCatalogue
            actorId={3}
            items={[
                {
                    ...item,
                    outcome_type: 'service_request',
                    site_options: [
                        { id: 21, name: 'Primary house' },
                        { id: 22, name: 'Cover house' },
                    ],
                    form_schema: { fields: [] },
                },
            ]}
            fieldOptions={{ employee: [], user: [], asset: [] }}
            query=""
            category={null}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Equipment request' }));
    expect(screen.getByRole('combobox', { name: 'Request site' })).toHaveValue(
        '21',
    );
    fireEvent.change(screen.getByRole('combobox', { name: 'Request site' }), {
        target: { value: '22' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Submit request' }));
    expect(state.post).toHaveBeenCalledWith(
        '/it/catalog/12/submissions',
        expect.objectContaining({
            site_id: 22,
            schema_version: item.form_schema_version,
        }),
    );
});

it('shows complete long Site names on a full publication-review row', () => {
    const siteName =
        'Approved residential support location with a long shared name — Site B';
    render(
        <ItCatalogueManagement
            actorId={3}
            items={[{ ...item, is_published: false, site_scope: [21] }]}
            services={[]}
            sites={[{ id: 21, name: siteName }]}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Review and publish' }));
    const publication = screen.getByRole('dialog', { name: 'Publish request' });
    expect(within(publication).getByText('Available at')).toBeVisible();
    const siteValue = within(publication).getByText(siteName);
    expect(siteValue).toBeVisible();
    expect(siteValue).not.toHaveClass('truncate');
    expect(siteValue).toHaveClass('whitespace-normal', 'break-words');
    expect(siteValue.parentElement).toHaveClass('col-span-2');
    expect(state.post).not.toHaveBeenCalled();
});
