import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { useState, type AnchorHTMLAttributes } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { DepartmentSpace, departmentPreview } from './_department';
import type { CreateHubPageInitial } from './_dialogs';
import type { HubContent, HubDepartment, HubPerson, HubProps } from './_types';

const { request, post } = vi.hoisted(() => ({
    request: vi.fn(),
    post: vi.fn(),
}));
vi.mock('./_api', () => ({ hubRequest: request }));
vi.mock('@inertiajs/react', () => ({
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    router: { post: vi.fn(), reload: vi.fn(), on: () => vi.fn() },
    usePage: () => ({
        props: {
            starterTemplates: [
                {
                    key: 'story',
                    label: 'Company story',
                    kind: 'news',
                    icon: 'newspaper',
                    purpose: 'Share a company update.',
                    block_types: ['text'],
                },
                {
                    key: 'leadership',
                    label: 'Leadership update',
                    kind: 'news',
                    icon: 'megaphone',
                    purpose: 'Share a leadership message.',
                    block_types: ['text'],
                },
            ],
        },
    }),
    useRemember: (initial: Record<string, string>) => useState(initial),
    useForm: function useForm(initial: CreateHubPageInitial) {
        const [data, setData] = useState(initial);
        return {
            data,
            errors: {},
            processing: false,
            setData: (
                key:
                    | string
                    | ((value: CreateHubPageInitial) => CreateHubPageInitial),
                value: string,
            ) =>
                typeof key === 'function'
                    ? setData(key)
                    : setData((old) => ({ ...old, [key]: value })),
            post: (url: string) => post(url, data),
        };
    },
}));
const content = {
    title: 'Learning together',
    summary: 'Our team space',
    category: 'Learning',
    hero_label: 'Our people',
    cover_id: null,
    featured: false,
    audience: { type: 'all', ids: [] },
    comments_enabled: false,
    reactions_enabled: false,
    requires_approval: false,
    show_in_navigation: false,
    navigation_order: 0,
    review_on: null,
    team_ids: [3],
    sections: [
        {
            id: 'intro',
            columns: 1,
            blocks: [
                {
                    id: 'intro',
                    type: 'text',
                    title: 'Meet our team',
                    body: 'Helping our people grow.',
                },
            ],
        },
    ],
} as HubContent;
const colleague: HubPerson = {
    id: 21,
    user_id: 11,
    name: 'Aroha Bennett',
    position: 'Learning advisor',
    department: 'Learning',
    department_id: 3,
    site: 'North',
    work_email: 'aroha@company.test',
    work_phone: null,
    first_aider: false,
    fire_warden: false,
};
const page = {
    id: 50,
    slug: 'learning-news',
    kind: 'news',
    title: 'Learning week',
    summary: 'A week to grow',
    category: 'Learning',
    cover_url: null,
    featured: false,
    published_at: '2026-10-12T00:00:00Z',
    show_in_navigation: false,
    navigation_order: 0,
    team_ids: [3],
};
const space: HubDepartment = {
    actor_id: 11,
    page_id: 8,
    revision_id: 9,
    version: 2,
    teams: [{ id: 3, name: 'Learning' }],
    owner: colleague,
    updates: [page],
    resources: [
        {
            ...page,
            id: 51,
            kind: 'knowledge',
            title: 'Learning handbook',
            slug: 'learning-handbook',
        },
    ],
    people: [colleague],
    can_create_update: true,
};
const data = {
    home: content,
    homeId: 1,
    pages: [page, { ...page, id: 60, title: 'Unrelated story', team_ids: [4] }],
    people: [
        colleague,
        {
            ...colleague,
            id: 22,
            user_id: 12,
            name: 'Another colleague',
            department_id: 4,
        },
    ],
    bookmarks: [],
    resourceItems: [
        {
            ...page,
            id: 61,
            kind: 'knowledge',
            title: 'Unrelated resource',
            team_ids: [4],
        },
    ],
    kudos: [],
    events: [],
    capabilities: {},
    interests: {
        actor_id: 11,
        version: 1,
        topics: ['Learning'],
        teams: [4],
        available_topics: ['Learning'],
        available_teams: [
            { id: 3, name: 'Learning' },
            { id: 4, name: 'Other team' },
        ],
    },
    selectedPage: {
        id: 8,
        kind: 'department',
        slug: 'learning',
        content,
        published_at: '2026-10-12T00:00:00Z',
        can_edit: true,
    },
} as unknown as HubProps;
const show = (projection = space, preview = false) =>
    render(
        <DepartmentSpace
            content={content}
            space={projection}
            data={data}
            preview={preview}
            onEvent={vi.fn()}
        />,
    );
const select = (name: RegExp) =>
    fireEvent.click(
        within(
            screen.getByRole('tablist', { name: 'Department space views' }),
        ).getByRole('tab', { name }),
    );

it('keeps sample team tabs inside the showcase and removes canonical people and follow actions', () => {
    render(
        <DepartmentSpace
            content={content}
            space={space}
            data={{ ...data, demo: true }}
            onEvent={vi.fn()}
        />,
    );
    expect(
        screen.queryByRole('button', { name: /Follow team/ }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'New update' }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('link', { name: colleague.name }),
    ).not.toBeInTheDocument();
    expect(
        screen.getByRole('link', { name: 'All team spaces' }),
    ).toHaveAttribute('href', '/company-hub/showcase?view=teams');
    select(/^Updates/);
    expect(screen.getByRole('link', { name: /Learning week/ })).toHaveAttribute(
        'href',
        '/company-hub/showcase?story=learning-news',
    );
    select(/^Resources/);
    expect(screen.getByRole('link', { name: 'All resources' })).toHaveAttribute(
        'href',
        '/company-hub/showcase?view=resources',
    );
    select(/^Our team/);
    expect(
        within(
            screen.getByRole('region', { name: 'Our team content' }),
        ).getByText(colleague.name),
    ).toBeVisible();
    expect(
        screen.queryByRole('link', { name: new RegExp(colleague.name) }),
    ).not.toBeInTheDocument();
    expect(request).not.toHaveBeenCalled();
});

beforeEach(() => {
    request.mockReset();
    post.mockReset();
});
describe('dedicated department spaces', () => {
    it('includes exact team documents and its own attachments without unrelated files', () => {
        const file = {
            ...page,
            id: 51,
            title: 'Team PDF',
            resource_type: 'File',
            document_id: 70,
            resource_key: 'file:51:pdf',
            reader_url: '/company-hub/documents/70',
        };
        render(
            <DepartmentSpace
                content={content}
                space={space}
                data={{
                    ...data,
                    resourceItems: [
                        file,
                        {
                            ...file,
                            id: space.page_id,
                            document_id: 71,
                            resource_key: 'file:8:pdf',
                            title: 'Space PDF',
                            reader_url: '/company-hub/documents/71',
                            team_ids: [],
                        },
                        {
                            ...file,
                            id: 90,
                            document_id: 72,
                            resource_key: 'file:90:pdf',
                            title: 'Other team PDF',
                            team_ids: [4],
                        },
                    ],
                }}
                onEvent={vi.fn()}
            />,
        );
        select(/^Resources/);
        expect(screen.getByRole('link', { name: 'Team PDF' })).toHaveAttribute(
            'href',
            '/company-hub/documents/70',
        );
        expect(screen.getByRole('link', { name: 'Space PDF' })).toHaveAttribute(
            'href',
            '/company-hub/documents/71',
        );
        expect(
            screen.queryByRole('article', { name: 'Other team PDF' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('article', { name: 'Learning handbook' }),
        ).toBeVisible();
    });
    it('switches between the overview and real team stories resources and work profiles', () => {
        show();
        expect(screen.getByText('Helping our people grow.')).toBeVisible();
        expect(
            screen.getByRole('link', { name: /^Aroha Bennett$/ }),
        ).toHaveAttribute('href', '/company-hub/people/21');
        select(/^Updates/);
        expect(
            screen.getByRole('link', { name: /Learning week/ }),
        ).toHaveAttribute('href', '/company-hub/read/learning-news');
        expect(screen.queryByText('Unrelated story')).not.toBeInTheDocument();
        select(/^Resources/);
        expect(
            screen.getByRole('link', { name: 'Learning handbook' }),
        ).toHaveAttribute('href', '/company-hub/read/learning-handbook');
        expect(
            screen.queryByText('Unrelated resource'),
        ).not.toBeInTheDocument();
        select(/^Our team/);
        expect(
            screen.getByRole('link', {
                name: /Aroha Bennett.*Learning advisor/,
            }),
        ).toHaveAttribute('href', '/company-hub/people/21');
        expect(screen.queryByText('Another colleague')).not.toBeInTheDocument();
        expect(screen.getByText('Published version 2')).toBeVisible();
    });
    it('starts an editable team news draft through the existing creator with its published source context', () => {
        show();
        fireEvent.click(screen.getByRole('button', { name: 'New update' }));
        const dialog = within(
            screen.getByRole('region', { name: 'Create a Company Hub page' }),
        );
        expect(
            dialog.getByRole('textbox', { name: 'What is this page called?' }),
        ).toHaveValue('An update from Learning');
        expect(
            dialog.queryByRole('button', { name: 'Department space' }),
        ).not.toBeInTheDocument();
        expect(dialog.getByText(/current audience/)).toBeVisible();
        fireEvent.change(
            dialog.getByRole('textbox', { name: 'What is this page called?' }),
            {
                target: { value: 'Our learning update' },
            },
        );
        fireEvent.click(
            dialog.getByRole('radio', { name: 'Leadership update' }),
        );
        fireEvent.click(
            dialog.getByRole('button', {
                name: 'Create draft & open designer',
            }),
        );
        expect(post).toHaveBeenCalledWith('/company-hub/pages', {
            title: 'Our learning update',
            kind: 'news',
            template: 'leadership',
            source_page_id: 8,
            source_revision_id: 9,
        });
    });
    it('waits for confirmed follow state and sends account revision and preference versions', async () => {
        let complete!: (value: unknown) => void;
        request.mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    complete = resolve;
                }),
        );
        show();
        fireEvent.click(screen.getByRole('button', { name: 'Follow team' }));
        expect(screen.getByRole('button', { name: 'Saving…' })).toBeDisabled();
        expect(request).toHaveBeenCalledWith(
            '/company-hub/pages/8/follow-teams',
            'PUT',
            { actor_id: 11, revision_id: 9, version: 1, following: true },
        );
        await act(async () => {
            complete({
                actor_id: 11,
                page_id: 8,
                revision_id: 9,
                interests: { ...data.interests, version: 2, teams: [4, 3] },
            });
        });
        expect(
            screen.getByRole('button', { name: 'Following team' }),
        ).toHaveAttribute('aria-pressed', 'true');
        request.mockResolvedValue({
            actor_id: 11,
            page_id: 8,
            revision_id: 9,
            interests: { ...data.interests, version: 3 },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Following team' }));
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Follow team' }),
            ).toHaveAttribute('aria-pressed', 'false'),
        );
        expect(request).toHaveBeenLastCalledWith(
            '/company-hub/pages/8/follow-teams',
            'PUT',
            { actor_id: 11, revision_id: 9, version: 2, following: false },
        );
    });
    it('retains the current choice on stale preferences or a mismatched account response', async () => {
        request.mockRejectedValueOnce(
            new Error('Your interests changed in another window.'),
        );
        show();
        fireEvent.click(screen.getByRole('button', { name: 'Follow team' }));
        await waitFor(() =>
            expect(screen.getByRole('alert')).toHaveTextContent(
                'another window',
            ),
        );
        expect(
            screen.getByRole('button', { name: 'Follow team' }),
        ).toHaveAttribute('aria-pressed', 'false');
        request.mockResolvedValueOnce({
            actor_id: 99,
            page_id: 8,
            revision_id: 9,
            interests: { ...data.interests, teams: [3] },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Follow team' }));
        await waitFor(() =>
            expect(screen.getByRole('alert')).toHaveTextContent(
                'signed-in account changed',
            ),
        );
        expect(
            screen.getByRole('button', { name: 'Follow team' }),
        ).toHaveAttribute('aria-pressed', 'false');
    });
    it('ignores a follow result after leaving the page', async () => {
        let complete!: (value: unknown) => void;
        request.mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    complete = resolve;
                }),
        );
        const first = show();
        fireEvent.click(screen.getByRole('button', { name: 'Follow team' }));
        first.unmount();
        show({ ...space, actor_id: 22 });
        await act(async () => {
            complete({
                actor_id: 11,
                page_id: 8,
                revision_id: 9,
                interests: { ...data.interests, version: 2, teams: [3] },
            });
        });
        expect(
            screen.getByRole('button', { name: 'Follow team' }),
        ).toHaveAttribute('aria-pressed', 'false');
    });
    it('previews related teams without personal follow create or bookmark writes', () => {
        const preview = departmentPreview(
            content,
            { id: 8, owner_id: 11, draft_version: 3 },
            data,
        );
        expect(preview.people.map((person) => person.id)).toEqual([21]);
        expect(preview.updates.map((item) => item.id)).toEqual([50]);
        show(space, true);
        expect(
            screen.queryByRole('button', { name: 'Follow team' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'New update' }),
        ).not.toBeInTheDocument();
        select(/^Resources/);
        expect(
            screen.queryByRole('button', { name: 'Save Learning handbook' }),
        ).not.toBeInTheDocument();
        expect(request).not.toHaveBeenCalled();
        expect(post).not.toHaveBeenCalled();
    });
    it('does not fill an unconnected team space with every company colleague or page', () => {
        const preview = departmentPreview(
            { ...content, team_ids: [] },
            { id: 8, owner_id: 11, draft_version: 3 },
            data,
        );
        show(preview, true);
        expect(
            screen.getByText(/Choose related teams in Page settings/),
        ).toBeVisible();
        select(/^Updates/);
        expect(
            screen.getByText(
                'Published stories from this team will appear here.',
            ),
        ).toBeVisible();
        select(/^Our team/);
        expect(screen.getByText(/No colleagues match/)).toBeVisible();
    });
});
