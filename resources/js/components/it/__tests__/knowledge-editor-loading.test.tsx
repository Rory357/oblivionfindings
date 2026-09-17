import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import axios from 'axios';
import { afterEach, expect, it, vi } from 'vitest';
import { ItWizard, type KbRow } from '../it-wizards';

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    usePage: () => ({ props: { auth: { user: { id: 7 } } } }),
}));

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

it('loads a proposal before editing and preserves typed changes after a later context refresh', async () => {
    const metadata = {
        id: 23,
        title: 'Published title',
        body: null,
        content_loaded: false,
        lock_version: 4,
        status: 'published',
        audience: 'all_staff',
        can: { author: true, review: false, manage: true, edit: true },
        working_copy: { status: 'draft', content: {} },
    } as KbRow;
    const loaded = {
        ...metadata,
        content_loaded: true,
        working_copy: {
            status: 'draft',
            content: {
                title: 'Loaded proposed title',
                category: 'network',
                body: 'Loaded proposed body.',
                audience: 'all_staff',
                owner_user_id: 7,
                review_due_at: '2026-10-12',
                document_type: 'runbook',
                structured_content: { procedure: 'Loaded procedure.' },
                related_records: [],
            },
        },
    };
    const options = {
        revisions_ready: true,
        owners: [{ id: 7, name: 'Synthetic author' }],
        sites: [],
        services: [],
    };
    let resolve!: (value: unknown) => void;
    const get = vi.spyOn(axios, 'get').mockImplementationOnce(
        () =>
            new Promise((done) => {
                resolve = done;
            }),
    );
    const close = vi.fn();
    const { rerender } = render(
        <ItWizard
            modal={{ type: 'kb', article: metadata }}
            assignees={[]}
            kbOptions={options}
            onClose={close}
        />,
    );
    expect(screen.getByText('Loading current document…')).toBeVisible();
    expect(
        screen.queryByDisplayValue('Published title'),
    ).not.toBeInTheDocument();
    await act(async () =>
        resolve({
            data: {
                actor_user_id: 7,
                editable: true,
                article: loaded,
                options,
            },
        }),
    );
    const title = await screen.findByDisplayValue('Loaded proposed title');
    fireEvent.change(title, { target: { value: 'My retained edit' } });

    get.mockResolvedValue({
        data: {
            actor_user_id: 7,
            editable: true,
            article: {
                ...loaded,
                working_copy: {
                    ...loaded.working_copy,
                    content: {
                        ...loaded.working_copy.content,
                        title: 'Later server title',
                    },
                },
            },
            options,
        },
    });
    rerender(
        <ItWizard
            modal={{ type: 'kb', article: metadata }}
            assignees={[]}
            kbOptions={{ ...options }}
            onClose={close}
        />,
    );
    await waitFor(() => expect(get).toHaveBeenCalledTimes(2));
    expect(await screen.findByDisplayValue('My retained edit')).toBeVisible();
    expect(
        screen.queryByDisplayValue('Later server title'),
    ).not.toBeInTheDocument();
    fireEvent.click(
        screen.getByRole('button', { name: /Content.*Write & preview/ }),
    );
    expect(
        await screen.findByDisplayValue('Loaded proposed body.'),
    ).toBeVisible();
    expect(screen.getByDisplayValue('Loaded procedure.')).toBeVisible();
    expect(close).not.toHaveBeenCalled();
});
