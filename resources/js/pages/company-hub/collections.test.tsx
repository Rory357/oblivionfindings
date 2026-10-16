import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    ResourceCollectionPicker,
    ResourceCollectionWorkspace,
    resourceCollectionChoices,
    resourceInCollection,
} from './_collections';
import { ResourceLibrary } from './_resource-library';
import { hubData, hubStory } from './_test-fixtures';
import type { HubCollectionContext } from './_types';

const { request, state, visit } = vi.hoisted(() => ({
    request: vi.fn(),
    visit: vi.fn(),
    state: { actor: 7 },
}));
vi.mock('./_api', () => ({ hubRequest: request }));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { user: { id: state.actor } } } }),
    router: { on: () => () => {}, visit },
    useRemember: (initial: Record<string, string>) => useState(initial),
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
}));
vi.mock('./_browse-select', () => ({
    HubBrowseSelect: ({
        label,
        value,
        options,
        onChange,
    }: {
        label: string;
        value: string;
        options: Array<{ value: string; label: string }>;
        onChange: (value: string) => void;
    }) => (
        <select
            aria-label={label}
            value={value}
            onChange={(e) => onChange(e.target.value)}
        >
            {options.map((item) => (
                <option key={item.value} value={item.value}>
                    {item.label}
                </option>
            ))}
        </select>
    ),
}));
const row = {
    id: 4,
    name: 'Getting started',
    version: 1,
    count: 2,
    can_rename: true,
};
const context = (
    changes: Partial<HubCollectionContext> = {},
): HubCollectionContext => ({
    ready: true,
    actor_id: 7,
    can_create: true,
    items: [row],
    options: [row],
    legacy_groups: [],
    ...changes,
});
const reply = (name = 'Welcome', version = 2, id = 4) => {
    const collection = { ...row, id, name, version };
    return {
        collection,
        resourceCollections: context({
            items: [collection],
            options: [collection],
        }),
    };
};
beforeEach(() => {
    request.mockReset();
    visit.mockReset();
    window.history.replaceState({}, '', '/company-hub?view=resources');
    state.actor = 7;
    request.mockResolvedValue(context());
});

describe('shared collection authoring', () => {
    it('creates once with the current actor and accepts only an acknowledged shared collection', async () => {
        let finish!: (result: unknown) => void;
        request.mockResolvedValueOnce(context()).mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
        render(<ResourceCollectionWorkspace onClose={vi.fn()} />);
        const name = await screen.findByRole('textbox', {
            name: 'Collection name',
        });
        fireEvent.change(name, { target: { value: '  Welcome  ' } });
        const create = screen.getByRole('button', {
            name: 'Create collection',
        });
        fireEvent.click(create);
        fireEvent.click(create);
        expect(request).toHaveBeenCalledTimes(2);
        expect(request).toHaveBeenLastCalledWith(
            '/company-hub/collections',
            'POST',
            { actor_id: 7, name: 'Welcome' },
        );
        expect(create).toBeDisabled();
        await act(async () => finish(reply('Welcome', 1, 8)));
        expect(await screen.findByText(/Collection created\./)).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Rename Welcome' }),
        ).toBeVisible();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
    it('preserves a failed rename and requires an explicit choice before replacing unsaved text', async () => {
        const close = vi.fn();
        request
            .mockResolvedValueOnce(context())
            .mockRejectedValueOnce(
                new Error('This collection changed. Reload before renaming.'),
            );
        render(<ResourceCollectionWorkspace onClose={close} />);
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Rename Getting started',
            }),
        );
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Collection name' }),
            { target: { value: 'First week' } },
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Save collection' }),
        );
        expect(request).toHaveBeenLastCalledWith(
            '/company-hub/collections/4',
            'PATCH',
            { actor_id: 7, version: 1, name: 'First week' },
        );
        expect(
            await screen.findByText(/This collection changed/),
        ).toBeVisible();
        expect(
            screen.getByRole('textbox', { name: 'Collection name' }),
        ).toHaveValue('First week');
        fireEvent.click(
            screen.getByRole('button', { name: 'Browse resources' }),
        );
        expect(close).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(
            screen.getByRole('textbox', { name: 'Collection name' }),
        ).toHaveValue('First week');
        fireEvent.click(screen.getByRole('button', { name: 'New collection' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard name and continue' }),
        );
        expect(
            screen.getByRole('textbox', { name: 'Collection name' }),
        ).toHaveValue('');
    });
    it('does not claim a rename succeeded when a different collection is acknowledged', async () => {
        request
            .mockResolvedValueOnce(context())
            .mockResolvedValueOnce(reply('Welcome', 2, 99));
        render(<ResourceCollectionWorkspace onClose={vi.fn()} />);
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Rename Getting started',
            }),
        );
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Collection name' }),
            { target: { value: 'Welcome' } },
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Save collection' }),
        );
        expect(await screen.findByText(/could not be confirmed/)).toBeVisible();
        expect(
            screen.getByRole('textbox', { name: 'Collection name' }),
        ).toHaveValue('Welcome');
        expect(
            screen.queryByText('Collection renamed.'),
        ).not.toBeInTheDocument();
    });
    it('uses the current authorised legacy-group binding only after an explicit adoption', async () => {
        request
            .mockResolvedValueOnce(
                context({
                    legacy_groups: [
                        {
                            key: 'legacy:abc',
                            name: 'Learning',
                            count: 3,
                            can_adopt: true,
                            adoption_key: 'signed-current-members',
                        },
                    ],
                }),
            )
            .mockResolvedValueOnce(reply('Learning', 1, 10));
        render(<ResourceCollectionWorkspace onClose={vi.fn()} />);
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Manage Learning as a collection',
            }),
        );
        expect(screen.getByText(/connect the 3 resources/)).toBeVisible();
        expect(request).toHaveBeenCalledTimes(1);
        fireEvent.click(
            screen.getByRole('button', { name: 'Create collection' }),
        );
        await screen.findByText(/Collection created\./);
        expect(request).toHaveBeenLastCalledWith(
            '/company-hub/collections/adopt',
            'POST',
            {
                actor_id: 7,
                name: 'Learning',
                key: 'legacy:abc',
                adoption_key: 'signed-current-members',
            },
        );
    });
    it('ignores a late save when the signed-in actor changes', async () => {
        let finish!: (result: unknown) => void;
        request
            .mockResolvedValueOnce(context())
            .mockImplementationOnce(
                () =>
                    new Promise((resolve) => {
                        finish = resolve;
                    }),
            )
            .mockResolvedValueOnce(
                context({
                    actor_id: 8,
                    can_create: false,
                    items: [],
                    options: [],
                }),
            );
        const view = render(<ResourceCollectionWorkspace onClose={vi.fn()} />);
        fireEvent.change(
            await screen.findByRole('textbox', { name: 'Collection name' }),
            { target: { value: 'Old account draft' } },
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Create collection' }),
        );
        state.actor = 8;
        view.rerender(<ResourceCollectionWorkspace onClose={vi.fn()} />);
        await waitFor(() => expect(request).toHaveBeenCalledTimes(3));
        await act(async () => finish(reply('Old account draft', 1, 8)));
        expect(
            screen.queryByText(/Collection created\./),
        ).not.toBeInTheDocument();
        expect(screen.queryByText('Old account draft')).not.toBeInTheDocument();
        expect(
            screen.getByRole('textbox', { name: 'Collection name' }),
        ).toHaveValue('');
        expect(
            screen.getByRole('button', { name: 'Create collection' }),
        ).toBeDisabled();
    });
    it('refuses another account’s fetched context', async () => {
        request.mockResolvedValueOnce(context({ actor_id: 8 }));
        render(<ResourceCollectionWorkspace onClose={vi.fn()} />);
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Your account has changed',
        );
        expect(
            screen.queryByRole('textbox', { name: 'Collection name' }),
        ).not.toBeInTheDocument();
    });
});

describe('resource membership and stable discovery', () => {
    it('retains an adopted group’s filter and current search when returning to the library', async () => {
        window.history.replaceState(
            {},
            '',
            '/company-hub?view=resources&search=Learning',
        );
        const legacy = context({
            items: [],
            options: [],
            legacy_groups: [
                {
                    key: 'legacy:abc',
                    name: 'Learning',
                    count: 1,
                    can_adopt: true,
                    adoption_key: 'current-binding',
                },
            ],
        });
        const resource = {
            ...hubStory,
            kind: 'knowledge',
            collection: 'Learning',
            collection_id: null,
        };
        request
            .mockResolvedValueOnce(legacy)
            .mockResolvedValueOnce(reply('Learning', 1, 10));
        const view = render(
            <ResourceLibrary
                data={hubData({
                    view: 'resources',
                    search: 'Learning',
                    pages: [resource],
                    resourceCollections: legacy,
                })}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Learning · 1 resource' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Collections' }));
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Manage Learning as a collection',
            }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Create collection' }),
        );
        await screen.findByText(/Collection created\./);
        fireEvent.click(
            screen.getByRole('button', { name: 'Browse resources' }),
        );
        await waitFor(() =>
            expect(visit).toHaveBeenCalledWith(
                '/company-hub?view=resources&search=Learning',
                expect.objectContaining({
                    only: [
                        'search',
                        'resourceCollections',
                        'pages',
                        'resourceItems',
                    ],
                }),
            ),
        );
        const updated = reply('Learning', 1, 10).resourceCollections;
        view.rerender(
            <ResourceLibrary
                data={hubData({
                    view: 'resources',
                    search: 'Learning',
                    pages: [{ ...resource, collection_id: 10 }],
                    resourceCollections: updated,
                })}
            />,
        );
        expect(
            screen.getByRole('article', { name: 'Learning week' }),
        ).toBeVisible();
        expect(
            screen.getByRole('combobox', { name: 'Resource collection' }),
        ).toHaveValue('collection:10');
    });
    it('keeps draft content untouched until an explicit collection choice and clears both fields', async () => {
        const change = vi.fn();
        render(
            <ResourceCollectionPicker
                actorId={7}
                content={{ collection: 'Legacy welcome', collection_id: null }}
                onChange={change}
            />,
        );
        const picker = await screen.findByRole('combobox', {
            name: 'Resource collection',
        });
        expect(change).not.toHaveBeenCalled();
        expect(picker).toHaveValue('legacy');
        fireEvent.change(picker, { target: { value: '4' } });
        expect(change).toHaveBeenLastCalledWith({
            collection_id: 4,
            collection: 'Getting started',
        });
        fireEvent.change(picker, { target: { value: 'none' } });
        expect(change).toHaveBeenLastCalledWith({
            collection_id: null,
            collection: null,
        });
    });
    it('does not erase a retained membership when options fail or are no longer available', async () => {
        const change = vi.fn();
        request
            .mockRejectedValueOnce(
                new Error('Options temporarily unavailable.'),
            )
            .mockResolvedValueOnce(context());
        render(
            <ResourceCollectionPicker
                actorId={7}
                content={{ collection: 'Retained', collection_id: 99 }}
                onChange={change}
            />,
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Your saved selection is kept',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
        expect(await screen.findByRole('combobox')).toHaveValue('99');
        expect(change).not.toHaveBeenCalled();
    });
    it('uses distinct parent counts and retains the selected identity after a rename', () => {
        const resource = {
            ...hubStory,
            id: 4,
            kind: 'knowledge',
            collection: 'Old name',
            collection_id: 4,
        };
        const file = {
            ...resource,
            resource_key: 'file:9',
            resource_type: 'File',
        };
        expect(resourceCollectionChoices([resource, file])[0].count).toBe(1);
        const choices = resourceCollectionChoices(
            [resource, file],
            context({ items: [{ ...row, name: 'New name', count: 1 }] }),
        );
        expect(choices[0]).toMatchObject({
            key: 'collection:4',
            name: 'New name',
            count: 1,
        });
        expect(resourceInCollection(resource, 'collection:4', choices)).toBe(
            true,
        );
        expect(resourceInCollection(file, 'collection:4', choices)).toBe(true);
        expect(
            resourceInCollection(
                { ...resource, collection_id: 8 },
                'collection:4',
                choices,
            ),
        ).toBe(false);
    });
});
