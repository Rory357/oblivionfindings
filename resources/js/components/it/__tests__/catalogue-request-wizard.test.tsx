import { router } from '@inertiajs/react';
import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axios, { AxiosError } from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { ItServiceCatalogue, type CatalogItem } from '../it-service-catalogue';

vi.mock('@inertiajs/react', () => ({
    router: { on: vi.fn(() => () => {}), visit: vi.fn() },
}));
const item: CatalogItem = {
    id: 12,
    name: 'Private system access',
    slug: 'system-access',
    description: 'Private catalogue description',
    outcome_type: 'service_request',
    category: 'software',
    default_priority: 'normal',
    requires_approval: true,
    form_schema_version: 2,
    site_options: [{ id: 21, name: 'Approved house' }],
    form_schema: {
        fields: [
            {
                key: 'details',
                label: 'What do you need?',
                type: 'text',
                required: true,
            },
        ],
    },
};
function renderForm(
    catalogItem: CatalogItem = item,
    draftRecoveryEnabled = false,
) {
    render(
        <ItServiceCatalogue
            actorId={3}
            items={[catalogItem]}
            fieldOptions={{ employee: [], user: [], asset: [] }}
            query=""
            category={null}
            draftRecoveryEnabled={draftRecoveryEnabled}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: item.name }));
}

it('shows requested-for only with permission and reviews a real selected label before submitting', async () => {
    const transport = vi
        .spyOn(axios, 'post')
        .mockImplementation(async (url, value) => {
            const body = value as Record<string, unknown>;
            if (String(url).endsWith('/requested-for/options'))
                return {
                    data: {
                        viewer_user_id: 3,
                        query_uuid: body.query_uuid,
                        catalog_item_id: 12,
                        schema_version: 2,
                        purpose: 'requested-for',
                        field_key: 'requested_for_user_id',
                        site_id: 21,
                        selected_id: body.selected_id,
                        selected: null,
                        next_cursor: null,
                        options: [
                            {
                                id: 55,
                                name: 'Synthetic colleague',
                                detail: 'Approved house',
                            },
                        ],
                    },
                };
            return saved(value);
        });
    renderForm({ ...item, can_request_for_others: true });
    fireEvent.click(
        screen.getByRole('checkbox', { name: 'Request for another person' }),
    );
    review();
    expect(screen.getByRole('alert')).toHaveTextContent(
        'Choose the person this request is for.',
    );
    fireEvent.click(screen.getByRole('combobox', { name: 'Requested for' }));
    fireEvent.click(
        await screen.findByRole('option', { name: /Synthetic colleague/ }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(
        within(screen.getByRole('dialog')).getByText('Synthetic colleague'),
    ).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Submit request' }));
    await waitFor(() =>
        expect(transport).toHaveBeenCalledWith(
            '/it/catalog/12/submissions',
            expect.objectContaining({ requested_for_user_id: 55 }),
            expect.anything(),
        ),
    );
});

it('ordinary requesters have no requested-for directory control', () => {
    renderForm();
    expect(
        screen.queryByRole('checkbox', { name: 'Request for another person' }),
    ).not.toBeInTheDocument();
});

it('labels technician-only fields and separates their answers in review', () => {
    renderForm({
        ...item,
        form_schema: {
            fields: [
                ...item.form_schema.fields!,
                {
                    key: 'fulfilment_note',
                    label: 'Fulfilment instructions',
                    type: 'textarea',
                    visibility: 'internal',
                },
            ],
        },
    });
    const note = screen.getByRole('textbox', {
        name: 'Fulfilment instructions',
    });
    expect(note).toHaveAccessibleDescription(
        'Internal IT detail. Only authorised IT staff can see this answer.',
    );
    fireEvent.change(note, {
        target: { value: 'Private technician instructions' },
    });
    review();
    expect(screen.getByText('Internal IT details')).toBeVisible();
    expect(
        screen.getByText('Only authorised IT staff can see these answers.'),
    ).toBeVisible();
    expect(screen.getByText('Private technician instructions')).toBeVisible();
});
function review() {
    fireEvent.change(
        screen.getByRole('textbox', { name: /What do you need/ }),
        { target: { value: 'Private request detail' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
}
function denied(status: number) {
    return new AxiosError('Synthetic', undefined, undefined, undefined, {
        status,
        statusText: 'Synthetic',
        data: {},
        headers: {},
        config: { headers: {} } as never,
    });
}
function saved(value: unknown) {
    const body =
        value instanceof FormData
            ? {
                  actor_user_id: Number(value.get('actor_user_id')),
                  idempotency_key: String(value.get('idempotency_key')),
              }
            : (value as { actor_user_id: number; idempotency_key: string });
    return {
        data: {
            status: 'committed',
            data: {
                viewer_user_id: body.actor_user_id,
                catalog_item_id: 12,
                request_uuid: body.idempotency_key,
                schema_version: 2,
                submission_id: 8,
                id: 9,
                result_type: 'ticket',
                reference: 'IT-000009',
                url: '/it/tickets/9',
                replayed: true,
            },
        },
    };
}
beforeEach(() => {
    sessionStorage.clear();
    vi.mocked(router.visit).mockClear();
});
afterEach(() => vi.restoreAllMocks());

it('reviews selected filenames, retains them through Back and validation, then uploads the actual files', async () => {
    const failure = denied(422);
    failure.response!.data = {
        errors: { 'values.evidence.0': ['The file content is not accepted.'] },
    };
    const transport = vi
        .spyOn(axios, 'post')
        .mockRejectedValueOnce(failure)
        .mockImplementationOnce(async (_url, body) => saved(body));
    renderForm({
        ...item,
        form_schema: {
            fields: [
                ...item.form_schema.fields!,
                {
                    key: 'evidence',
                    label: 'Supporting files',
                    type: 'attachment',
                    required: true,
                    max: 2,
                },
            ],
        },
    });
    const original = new File(['Proof bytes'], 'proof.txt', {
        type: 'text/plain',
    });
    fireEvent.change(
        screen.getByLabelText(/Supporting files/, { selector: 'input' }),
        {
            target: { files: [original] },
        },
    );
    review();
    expect(screen.getByText('proof.txt (1 KB)')).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: /^Back$/ }));
    expect(
        screen.getByRole('button', { name: 'Remove proof.txt' }),
    ).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.click(screen.getByRole('button', { name: 'Submit request' }));
    await waitFor(() =>
        expect(
            screen.getByLabelText(/Supporting files/, { selector: 'input' }),
        ).toHaveAttribute('aria-invalid', 'true'),
    );
    expect(
        screen.getByLabelText(/Supporting files/, { selector: 'input' }),
    ).toHaveAccessibleDescription(/The file content is not accepted/);
    expect(
        screen.getByRole('button', { name: 'Remove proof.txt' }),
    ).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Remove proof.txt' }));
    expect(
        screen.queryByText('The file content is not accepted.'),
    ).not.toBeInTheDocument();
    expect(
        screen.getByLabelText(/Supporting files/, { selector: 'input' }),
    ).toHaveFocus();
    const replacement = new File(['Accepted bytes'], 'accepted.txt', {
        type: 'text/plain',
    });
    fireEvent.change(
        screen.getByLabelText(/Supporting files/, { selector: 'input' }),
        {
            target: { files: [replacement] },
        },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.click(screen.getByRole('button', { name: 'Submit request' }));
    await screen.findByText('Request saved');
    const body = transport.mock.calls[1][1] as FormData;
    expect(body.get('values[evidence][0]')).toBe(replacement);
    expect(body.get('values[details]')).toBe('Private request detail');
});

it('rejects unsupported or excessive file selections without losing accepted selections', () => {
    renderForm({
        ...item,
        form_schema: {
            fields: [
                ...item.form_schema.fields!,
                {
                    key: 'evidence',
                    label: 'Supporting files',
                    type: 'attachment',
                    max: 1,
                },
            ],
        },
    });
    const picker = screen.getByLabelText('Supporting files');
    fireEvent.change(picker, {
        target: { files: [new File(['script'], 'unsafe.svg')] },
    });
    expect(screen.getByRole('alert')).toHaveTextContent(
        'Script and web files are not accepted',
    );
    fireEvent.change(picker, {
        target: { files: [new File(['proof'], 'proof.txt')] },
    });
    fireEvent.change(picker, {
        target: { files: [new File(['extra'], 'extra.txt')] },
    });
    expect(screen.getByRole('alert')).toHaveTextContent('no more than 1 files');
    expect(
        screen.getByRole('button', { name: 'Remove proof.txt' }),
    ).toBeVisible();
    expect(
        screen.queryByRole('button', { name: 'Remove extra.txt' }),
    ).not.toBeInTheDocument();
});

it('focuses remount recovery and removes unavailable step buttons from keyboard interaction', async () => {
    sessionStorage.setItem(
        'it.catalogue.pending.v1.actor.3.item.12',
        JSON.stringify({
            actorId: 3,
            itemId: 12,
            schemaVersion: 2,
            requestUuid: 'e0000000-0000-4000-8000-000000000000',
        }),
    );
    renderForm();
    const dialog = screen.getByRole('dialog');
    await waitFor(() =>
        expect(within(dialog).getByRole('status')).toHaveFocus(),
    );
    expect(
        within(dialog).getByRole('button', {
            name: /Request details\s*Site and information/,
        }),
    ).toBeDisabled();
    expect(
        within(dialog).getByRole('button', {
            name: /Review request\s*Check before submitting/,
        }),
    ).toBeDisabled();
    expect(within(dialog).queryByText(/Step 1 of 2/)).not.toBeInTheDocument();
    expect(
        within(dialog).getByRole('button', { name: 'Check saved result' }),
    ).toBeEnabled();
});

it('requires valid details and a separate review before submitting, with keyboard focus on the invalid field', async () => {
    const transport = vi
        .spyOn(axios, 'post')
        .mockImplementation(async (_url, body) => saved(body));
    renderForm();
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(transport).not.toHaveBeenCalled();
    await waitFor(() =>
        expect(
            screen.getByRole('textbox', { name: /What do you need/ }),
        ).toHaveFocus(),
    );
    review();
    expect(screen.getByText('Private request detail')).toBeVisible();
    expect(
        within(screen.getByRole('dialog')).getByText('Approval required'),
    ).toBeVisible();
    expect(transport).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Submit request' }));
    await waitFor(() =>
        expect(
            screen.getByRole('button', { name: 'View request' }),
        ).toBeVisible(),
    );
    fireEvent.click(screen.getByRole('button', { name: 'View request' }));
    expect(router.visit).toHaveBeenCalledWith('/it/tickets/9');
});

it.each([403, 404])(
    'removes the form, review and catalogue private content after submission denial %s',
    async (status) => {
        vi.spyOn(axios, 'post').mockRejectedValueOnce(denied(status));
        renderForm();
        review();
        fireEvent.click(screen.getByRole('button', { name: 'Submit request' }));
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Return to IT & Support' }),
            ).toBeVisible(),
        );
        expect(document.body).not.toHaveTextContent('Private request detail');
        expect(document.body).not.toHaveTextContent(item.name);
        expect(document.body).not.toHaveTextContent(item.description!);
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Retry original request' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Return to IT & Support' }),
        );
        expect(router.visit).toHaveBeenCalledWith('/it');
    },
);

it('conceals private content on session expiry and reveals only the confirmed result after recovery', async () => {
    const transport = vi
        .spyOn(axios, 'post')
        .mockRejectedValueOnce(denied(419))
        .mockImplementationOnce(async (_url, body) => saved(body));
    renderForm();
    review();
    fireEvent.click(screen.getByRole('button', { name: 'Submit request' }));
    await waitFor(() =>
        expect(
            screen.getByRole('link', { name: 'Sign in again' }),
        ).toBeVisible(),
    );
    expect(document.body).not.toHaveTextContent('Private request detail');
    expect(document.body).not.toHaveTextContent(item.name);
    fireEvent.click(screen.getByRole('button', { name: 'Check saved result' }));
    await waitFor(() =>
        expect(
            screen.getByRole('button', { name: 'View request' }),
        ).toBeVisible(),
    );
    expect(transport.mock.calls[1][0]).toBe(
        '/it/catalog/12/submissions/recover',
    );
    expect(transport.mock.calls[1][1]).not.toHaveProperty('values');
    expect(document.body).not.toHaveTextContent('Private request detail');
});

it('keeps dirty details after Escape and cancellation of discard, then discards explicitly', async () => {
    renderForm();
    fireEvent.change(
        screen.getByRole('textbox', { name: /What do you need/ }),
        { target: { value: 'Unsent detail' } },
    );
    const unload = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(unload);
    expect(unload.defaultPrevented).toBe(true);
    fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
    const confirmation = await screen.findByRole('alertdialog');
    expect(within(confirmation).getByText('Discard this draft?')).toBeVisible();
    fireEvent.click(
        within(confirmation).getByRole('button', { name: 'Cancel' }),
    );
    expect(
        screen.getByRole('textbox', { name: /What do you need/ }),
    ).toHaveValue('Unsent detail');
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    fireEvent.click(
        within(screen.getByRole('alertdialog')).getByRole('button', {
            name: 'Discard draft',
        }),
    );
    await waitFor(() =>
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
    );
    expect(sessionStorage.length).toBe(0);
});

it('requires explicit cancellation and never shows success for an unconfirmed response', async () => {
    const transport = vi
        .spyOn(axios, 'post')
        .mockResolvedValueOnce({ data: { success: true } })
        .mockImplementationOnce(async (_url, body) => ({
            data: {
                status: 'cancelled',
                data: {
                    viewer_user_id: 3,
                    catalog_item_id: 12,
                    request_uuid: (body as { idempotency_key: string })
                        .idempotency_key,
                    cancelled: true,
                },
            },
        }));
    renderForm();
    review();
    fireEvent.click(screen.getByRole('button', { name: 'Submit request' }));
    await waitFor(() =>
        expect(
            screen.getByRole('button', { name: 'Check saved result' }),
        ).toBeVisible(),
    );
    expect(
        screen.queryByRole('button', { name: 'View request' }),
    ).not.toBeInTheDocument();
    fireEvent.click(
        screen.getByRole('button', { name: 'Cancel unconfirmed request' }),
    );
    expect(transport).toHaveBeenCalledTimes(1);
    fireEvent.click(
        within(screen.getByRole('alertdialog')).getByRole('button', {
            name: 'Confirm cancellation',
        }),
    );
    await waitFor(() =>
        expect(
            screen.getByRole('heading', { name: 'Request cancelled' }),
        ).toBeVisible(),
    );
    expect(transport.mock.calls[1][0]).toBe(
        '/it/catalog/12/submissions/cancel',
    );
    expect(sessionStorage.length).toBe(0);
});
