import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { useState, type AnchorHTMLAttributes } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { HubBlocks } from './_blocks';
import { HubBookmarksProvider } from './_bookmarks';
import {
    HubBrowseLink,
    browseReturnLink,
    readBrowseReturn,
} from './_browse-state';
import { HubNewsroom } from './_discovery';
import { NewsCards } from './_news-cards';
import { HubPageCreationContext } from './_page-creation-context';
import { ResourceLibrary } from './_resource-library';
import { hubData, hubStory } from './_test-fixtures';
import type { HubPageSummary } from './_types';

const { post, visit, request } = vi.hoisted(() => ({
    post: vi.fn(),
    visit: vi.fn(),
    request: vi.fn(),
}));
vi.mock('./_api', () => ({ hubRequest: request }));
vi.mock('@inertiajs/react', () => ({
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    router: { post, visit },
    useRemember: (initial: Record<string, string>) => useState(initial),
}));
vi.mock('./_dialogs', () => ({
    CreateHubPageDialog: ({
        open,
        initial,
    }: {
        open: boolean;
        initial: { kind: string; template: string };
    }) =>
        open ? (
            <div role="dialog" aria-label={'Create ' + initial.kind}>
                {initial.template}
            </div>
        ) : null,
}));

const story: HubPageSummary = {
    ...hubStory,
    author: 'Aroha Bennett',
    owner: 'Jamie Wilson',
    topics: ['Learning'],
    team_names: ['Learning team'],
    read_minutes: 3,
    comments_enabled: true,
    comment_count: 2,
};
const guide: HubPageSummary = {
    ...story,
    id: 60,
    kind: 'knowledge',
    slug: 'welcome',
    title: 'Your first week',
    summary: 'Useful steps for new colleagues.',
    author: 'Jamie Wilson',
    owner: 'Morgan Reed',
    category: 'People',
    topics: ['Digital tools'],
    collection: 'Getting started',
    resource_type: 'Process',
    team_ids: [3, 4],
    team_names: ['Care', 'Learning team'],
    review_on: '2026-01-01',
    review_overdue: true,
    cover_url: '/company-hub/media/7',
};
const handbook: HubPageSummary = {
    ...guide,
    id: 61,
    slug: 'handbook',
    title: 'Digital tools handbook',
    summary: 'Contact the team for help.',
    owner: 'Alex Smith',
    collection: 'Everyday work',
    resource_type: 'Guide',
    team_ids: [4],
    team_names: ['Care'],
    topics: [],
    published_at: '2026-01-01T00:00:00Z',
    review_overdue: false,
    cover_url: '/company-hub/media/8',
};
const resources = () =>
    hubData({
        pages: [guide, handbook],
        interests: {
            actor_id: 11,
            version: 1,
            topics: [],
            teams: [],
            available_topics: [],
            available_teams: [
                { id: 4, name: 'Care' },
                { id: 3, name: 'Learning team' },
            ],
        },
        recentPages: [60, 61],
    });
beforeEach(() => {
    request.mockReset();
    vi.clearAllMocks();
    window.history.replaceState({}, '', '/company-hub');
});

describe('Published story discovery', () => {
    it('opens shared creation with the story starter instead of a local overlay', () => {
        const create = vi.fn();
        render(
            <HubPageCreationContext.Provider value={create}>
                <HubNewsroom
                    data={hubData({ capabilities: { write: true } })}
                />
            </HubPageCreationContext.Provider>,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Write a story' }));
        expect(create).toHaveBeenCalledWith(
            expect.objectContaining({
                initial: { title: '', kind: 'news', template: 'story' },
            }),
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
    it('searches author, topic and team without including stories opted out of company news', () => {
        render(
            <HubNewsroom
                data={hubData({
                    pages: [
                        story,
                        {
                            ...story,
                            id: 51,
                            title: 'Excluded story',
                            show_in_news: false,
                        },
                        {
                            ...story,
                            id: 52,
                            title: 'Different team',
                            author: 'Someone else',
                            owner: 'Other colleague',
                            team_names: ['Garden'],
                            topics: ['Wellbeing'],
                        },
                    ],
                })}
            />,
        );
        fireEvent.change(screen.getByRole('textbox', { name: 'Search news' }), {
            target: { value: ' AROHA learning ' },
        });
        expect(
            screen.getByRole('article', { name: 'Learning week' }),
        ).toBeVisible();
        expect(
            screen.queryByRole('article', { name: 'Excluded story' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('article', { name: 'Different team' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Clear filters' }));
        expect(screen.getAllByRole('article')).toHaveLength(2);
        fireEvent.click(screen.getByRole('button', { name: 'News list view' }));
        expect(
            screen.getByRole('article', { name: 'Learning week' })
                .parentElement,
        ).toHaveAttribute('data-layout', 'list');
    });
    it('shows real author/read-time/comment metadata with separate accessible links and save controls', () => {
        request.mockReturnValue(new Promise(() => {}));
        render(
            <HubBookmarksProvider actorId={11} initial={[]}>
                <NewsCards pages={[story]} bookmarks={[]} />
            </HubBookmarksProvider>,
        );
        const card = screen.getByRole('article', { name: story.title });
        expect(within(card).getByText('Aroha Bennett')).toBeVisible();
        expect(within(card).getByText('3 min read')).toBeVisible();
        expect(
            within(card).getByRole('link', {
                name: '2 comments on Learning week',
            }),
        ).toHaveAttribute(
            'href',
            '/company-hub/read/learning-week#hub-conversation',
        );
        const save = within(card).getByRole('button', {
            name: 'Save Learning week',
        });
        expect(save.closest('a')).toBeNull();
        fireEvent.click(save);
        fireEvent.click(save);
        expect(request).toHaveBeenCalledTimes(1);
        expect(request).toHaveBeenCalledWith('/company-hub/bookmarks', 'POST', {
            hub_receipt: true,
            actor_id: 11,
            subject_type: 'page',
            subject_id: 50,
            saved: true,
            expected_saved: false,
            expected_version: null,
        });
    });
    it('retains the last confirmed saved state after a failed bookmark request and offers a recheck', async () => {
        request.mockRejectedValueOnce(
            new Error('This story is no longer available.'),
        );
        render(
            <HubBookmarksProvider
                actorId={11}
                initial={[
                    {
                        subject_type: 'page',
                        subject_id: 50,
                        version: 'a'.repeat(64),
                    },
                ]}
            >
                <NewsCards
                    pages={[story]}
                    bookmarks={[{ subject_type: 'page', subject_id: 50 }]}
                />
            </HubBookmarksProvider>,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Unsave Learning week' }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'no longer available',
        );
        expect(
            screen.getByRole('button', { name: 'Unsave Learning week' }),
        ).toHaveAttribute('aria-pressed', 'true');
        expect(
            screen.getByRole('button', { name: 'Unsave Learning week' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Check saved state' }),
        ).toBeEnabled();
    });
    it('keeps demo and editor previews free of bookmark mutations', () => {
        const data = hubData({ pages: [story] });
        const view = render(
            <HubBlocks
                preview
                data={data}
                onEvent={vi.fn()}
                sections={[
                    {
                        id: 'news',
                        columns: 1,
                        blocks: [{ id: 'news', type: 'news', title: 'News' }],
                    },
                ]}
            />,
        );
        expect(
            screen.queryByRole('button', { name: 'Save Learning week' }),
        ).not.toBeInTheDocument();
        view.rerender(<NewsCards demo pages={[story]} bookmarks={[]} />);
        expect(
            screen.queryByRole('button', { name: 'Save Learning week' }),
        ).not.toBeInTheDocument();
        expect(post).not.toHaveBeenCalled();
    });
    it('does not show a conversation count when comments are closed or metadata is missing', () => {
        render(
            <NewsCards
                pages={[
                    { ...story, comments_enabled: false },
                    { ...hubStory, id: 52, title: 'Legacy story' },
                ]}
            />,
        );
        expect(
            screen.queryByRole('link', { name: /comments on/ }),
        ).not.toBeInTheDocument();
        expect(screen.queryByText('undefined')).not.toBeInTheDocument();
    });
    it('offers the story starter only to real authorised writers', () => {
        const view = render(<HubNewsroom data={hubData()} />);
        expect(
            screen.queryByRole('button', { name: 'Write a story' }),
        ).not.toBeInTheDocument();
        view.rerender(
            <HubNewsroom data={hubData({ capabilities: { write: true } })} />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Write a story' }));
        expect(
            screen.getByRole('dialog', { name: 'Create news' }),
        ).toHaveTextContent('story');
    });
});

describe('Resource collections and facets', () => {
    it('keeps recently opened stories available in the All pages browser', () => {
        render(
            <ResourceLibrary
                allPages
                data={hubData({
                    pages: [hubStory],
                    recentPages: [hubStory.id],
                    recentResources: [],
                })}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Recently opened' }),
        );
        expect(
            screen.getByRole('article', { name: hubStory.title }),
        ).toBeVisible();
    });
    it('keeps recent guide and document identities separate and sorts opened items first', () => {
        const document = {
            ...guide,
            id: 60,
            document_id: 44,
            reader_url: '/company-hub/documents/44',
            title: 'Opened PDF',
            resource_key: 'media:44',
        };
        const data = {
            ...resources(),
            recentResources: ['media:44', `page:${handbook.id}`],
            resourceItems: [
                document,
                {
                    ...document,
                    document_id: 45,
                    title: 'Unopened sibling PDF',
                    resource_key: 'media:45',
                    reader_url: '/company-hub/documents/45',
                },
            ],
        };
        render(<ResourceLibrary data={data} />);
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Recently opened',
            }),
        );
        expect(
            screen
                .getAllByRole('article')
                .map((item) => item.getAttribute('aria-label')),
        ).toEqual(['Opened PDF', handbook.title]);
        expect(
            screen.queryByRole('article', { name: guide.title }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('article', { name: 'Unopened sibling PDF' }),
        ).not.toBeInTheDocument();
    });
    it('surfaces three featured guides with exact destinations and filters the complete featured set', () => {
        const pages = Array.from({ length: 5 }, (_, index) => ({
            ...guide,
            id: 100 + index,
            slug: `guide-${index}`,
            title: `Guide ${index}`,
            featured: index < 4,
        }));
        render(<ResourceLibrary guidesOnly data={hubData({ pages })} />);
        const featured = screen.getByRole('region', {
            name: 'Featured guides',
        });
        expect(within(featured).getAllByRole('link')).toHaveLength(3);
        const destination = new URL(
            within(featured).getAllByRole('link')[0].getAttribute('href')!,
            'https://oblivionfindings.test',
        );
        expect(destination.pathname).toBe('/company-hub/read/guide-3');
        expect(
            JSON.parse(destination.searchParams.get('hubBrowse')!),
        ).toMatchObject({ view: 'guides', focus: 'featured-guide-103' });
        fireEvent.click(
            screen.getByRole('button', { name: 'View all featured' }),
        );
        expect(
            screen.queryByRole('region', { name: 'Featured guides' }),
        ).not.toBeInTheDocument();
        expect(screen.getAllByRole('article')).toHaveLength(4);
        expect(
            screen.queryByRole('article', { name: 'Guide 4' }),
        ).not.toBeInTheDocument();
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Search resources' }),
            { target: { value: 'Guide 2' } },
        );
        expect(screen.getAllByRole('article')).toHaveLength(1);
        expect(screen.getByRole('article', { name: 'Guide 2' })).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Clear filters' }));
        expect(
            screen.getByRole('region', { name: 'Featured guides' }),
        ).toBeVisible();
        expect(screen.getAllByRole('article')).toHaveLength(5);
    });
    it('includes canonical guide-like pages and knowledge guides in Company guides', () => {
        render(
            <ResourceLibrary
                guidesOnly
                data={hubData({
                    pages: [
                        {
                            ...hubStory,
                            id: 61,
                            kind: 'knowledge',
                            title: 'Knowledge guide',
                        },
                        {
                            ...hubStory,
                            id: 62,
                            kind: 'page',
                            resource_type: 'Policy',
                            title: 'Policy guide',
                        },
                        {
                            ...hubStory,
                            id: 63,
                            kind: 'page',
                            resource_type: 'Information',
                            title: 'General page',
                        },
                        {
                            ...hubStory,
                            id: 64,
                            kind: 'news',
                            resource_type: 'Guide',
                            title: 'News story',
                        },
                    ],
                })}
            />,
        );
        expect(
            screen.getByRole('article', { name: 'Knowledge guide' }),
        ).toBeVisible();
        expect(
            screen.getByRole('article', { name: 'Policy guide' }),
        ).toBeVisible();
        expect(
            screen.queryByRole('article', { name: 'General page' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('article', { name: 'News story' }),
        ).not.toBeInTheDocument();
    });
    it('opens shared creation with the resource starter', () => {
        const create = vi.fn();
        render(
            <HubPageCreationContext.Provider value={create}>
                <ResourceLibrary
                    data={{ ...resources(), capabilities: { write: true } }}
                />
            </HubPageCreationContext.Provider>,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Create guide' }));
        expect(create).toHaveBeenCalledWith(
            expect.objectContaining({
                initial: { title: '', kind: 'knowledge', template: 'guide' },
            }),
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
    it('uses canonical team IDs even when team labels arrive in a different order, and clears all filters', () => {
        render(<ResourceLibrary data={resources()} />);
        fireEvent.click(screen.getByRole('button', { name: /^Learning team/ }));
        expect(
            screen.getByRole('article', { name: guide.title }),
        ).toBeVisible();
        expect(
            screen.queryByRole('article', { name: handbook.title }),
        ).not.toBeInTheDocument();
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Search resources' }),
            { target: { value: ' Morgan digital ' } },
        );
        expect(
            screen.getByRole('article', { name: guide.title }),
        ).toBeVisible();
        fireEvent.keyDown(
            screen.getByRole('combobox', { name: 'Resource type' }),
            { key: 'Enter' },
        );
        fireEvent.click(screen.getByRole('option', { name: /^Guide$/ }));
        expect(screen.queryAllByRole('article')).toHaveLength(0);
        fireEvent.click(screen.getByRole('button', { name: 'Clear filters' }));
        expect(screen.getAllByRole('article')).toHaveLength(2);
        expect(
            screen.getByRole('button', { name: /^All teams/ }),
        ).toHaveAttribute('aria-pressed', 'true');
    });
    it('uses compact collection controls to filter resources and shows review care information', () => {
        render(<ResourceLibrary data={resources()} />);
        const collection = screen.getByRole('button', {
            name: 'Getting started · 1 resource',
        });
        fireEvent.click(collection);
        expect(
            screen.queryByRole('article', { name: handbook.title }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('combobox', { name: 'Resource collection' }),
        ).toHaveTextContent('Getting started');
        expect(screen.getByText(/Review overdue/)).toBeVisible();
        expect(
            screen.getByRole('link', { name: 'Ask for help' }),
        ).toHaveAttribute('href', '/company-hub?view=services');
    });
    it('ranks title matches first but respects an explicitly selected publication order', () => {
        render(<ResourceLibrary data={resources()} />);
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Search resources' }),
            { target: { value: 'digital' } },
        );
        expect(
            screen
                .getAllByRole('article')
                .map((item) => item.getAttribute('aria-label')),
        ).toEqual([handbook.title, guide.title]);
        fireEvent.keyDown(
            screen.getByRole('combobox', { name: 'Sort resources' }),
            { key: 'Enter' },
        );
        fireEvent.click(
            screen.getByRole('option', { name: 'Recently published' }),
        );
        expect(
            screen
                .getAllByRole('article')
                .map((item) => item.getAttribute('aria-label')),
        ).toEqual([guide.title, handbook.title]);
        fireEvent.click(screen.getByRole('button', { name: 'List view' }));
        expect(screen.getAllByRole('article')[0].parentElement).toHaveAttribute(
            'data-layout',
            'list',
        );
    });
    it('keeps file bookmarks on their parent page and supports saved and recent reading views', () => {
        const data = {
            ...resources(),
            resourceItems: [
                {
                    ...guide,
                    title: 'Starter checklist PDF',
                    resource_key: 'file:60:download',
                    resource_url: '/company-hub/media/44',
                    resource_type: 'File',
                },
            ],
            bookmarks: [{ subject_type: 'page', subject_id: 60 }],
        };
        request.mockReturnValue(new Promise(() => {}));
        render(
            <HubBookmarksProvider
                actorId={11}
                initial={[
                    {
                        subject_type: 'page',
                        subject_id: 60,
                        version: 'a'.repeat(64),
                    },
                ]}
            >
                <ResourceLibrary data={data} />
            </HubBookmarksProvider>,
        );
        fireEvent.click(screen.getByRole('button', { name: /^Saved$/ }));
        expect(screen.getAllByRole('article')).toHaveLength(2);
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Unsave the page for Starter checklist PDF',
            }),
        );
        expect(request).toHaveBeenCalledWith('/company-hub/bookmarks', 'POST', {
            hub_receipt: true,
            actor_id: 11,
            subject_type: 'page',
            subject_id: 60,
            saved: false,
            expected_saved: true,
            expected_version: 'a'.repeat(64),
        });
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Recently opened',
            }),
        );
        expect(
            screen.getByRole('combobox', { name: 'Sort resources' }),
        ).toHaveTextContent('Recently opened');
    });
    it('keeps previews read-only and supplies the knowledge starter for authorised writers', () => {
        const data = { ...resources(), capabilities: { write: true } };
        const view = render(<ResourceLibrary data={data} preview />);
        expect(
            screen.queryByRole('button', { name: 'Create guide' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Save Your first week' }),
        ).not.toBeInTheDocument();
        view.rerender(<ResourceLibrary data={data} />);
        fireEvent.click(screen.getByRole('button', { name: 'Create guide' }));
        expect(
            screen.getByRole('dialog', { name: 'Create knowledge' }),
        ).toHaveTextContent('guide');
    });
});

describe('Connected reading and return navigation', () => {
    it('restores keyboard focus and the prior scroll position after returning to a filtered story list', async () => {
        const scroll = vi
            .spyOn(window, 'scrollTo')
            .mockImplementation(() => {});
        window.history.replaceState(
            {},
            '',
            '/company-hub?view=news&hubBrowse=' +
                encodeURIComponent(
                    JSON.stringify({
                        view: 'news',
                        filters: { query: 'Aroha' },
                        focus: 'story-50',
                        scroll: { window: 325, regions: [] },
                    }),
                ),
        );
        render(<HubNewsroom data={hubData({ pages: [story] })} />);
        await waitFor(() =>
            expect(document.activeElement).toHaveAttribute(
                'data-hub-return-key',
                'story-50',
            ),
        );
        expect(scroll).toHaveBeenCalledWith({ top: 325, behavior: 'instant' });
        fireEvent.change(screen.getByRole('textbox', { name: 'Search news' }), {
            target: { value: 'Learning' },
        });
        expect(scroll).toHaveBeenCalledTimes(1);
        scroll.mockRestore();
    });
    it('carries the current filters, layout and focus into a story without nesting the conversation anchor', () => {
        render(<HubNewsroom data={hubData({ pages: [story] })} />);
        fireEvent.change(screen.getByRole('textbox', { name: 'Search news' }), {
            target: { value: 'Aroha' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'News list view' }));
        const link = screen.getByRole('link', {
            name: '2 comments on Learning week',
        });
        fireEvent.click(link);
        const url = new URL(visit.mock.calls[0][0], window.location.origin);
        expect(url.hash).toBe('#hub-conversation');
        expect(readBrowseReturn(url.search)).toMatchObject({
            view: 'news',
            filters: { query: 'Aroha', layout: 'list' },
            focus: 'discussion-50',
            scroll: { window: 0 },
        });
        expect(browseReturnLink('/company-hub', url.search)?.href).toContain(
            '/company-hub?view=news&hubBrowse=',
        );
    });
    it('restores resource filters and layout from an explicit return link', () => {
        window.history.replaceState(
            {},
            '',
            '/company-hub?view=resources&hubBrowse=' +
                encodeURIComponent(
                    JSON.stringify({
                        view: 'resources',
                        filters: {
                            search: 'Morgan',
                            team: '3',
                            layout: 'cards',
                        },
                    }),
                ),
        );
        render(<ResourceLibrary data={resources()} />);
        expect(
            screen.getByRole('textbox', { name: 'Search resources' }),
        ).toHaveValue('Morgan');
        expect(
            screen.getByRole('button', { name: /^Learning team/ }),
        ).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getAllByRole('article')).toHaveLength(1);
        expect(
            screen.getByRole('button', { name: 'Card view' }),
        ).toHaveAttribute('aria-pressed', 'true');
    });
    it('does not intercept modified clicks, and copied links still carry return filters', () => {
        render(
            <HubBrowseLink
                href="/company-hub/read/guide"
                returnKey="resource-60"
                browse={{ view: 'resources', filters: { team: '3' } }}
            >
                Open guide
            </HubBrowseLink>,
        );
        const link = screen.getByRole('link', { name: 'Open guide' });
        fireEvent.click(link, { ctrlKey: true });
        expect(visit).not.toHaveBeenCalled();
        expect(
            readBrowseReturn(
                new URL(link.getAttribute('href')!, window.location.origin)
                    .search,
            )?.filters,
        ).toEqual({ team: '3' });
    });
    it('rejects malformed or external return targets and strips unsupported fields', () => {
        const query = (value: unknown) =>
            '?hubBrowse=' + encodeURIComponent(JSON.stringify(value));
        expect(
            browseReturnLink(
                '/company-hub',
                query({ view: 'https://example.test', filters: {} }),
            ),
        ).toBeNull();
        expect(readBrowseReturn('?hubBrowse=not-json')).toBeNull();
        expect(
            readBrowseReturn(
                query({
                    view: 'news',
                    filters: {
                        query: 'care',
                        html: '<script>',
                        token: 'private',
                    },
                    focus: '"] body',
                    scroll: { window: -1, regions: [] },
                }),
            ),
        ).toEqual({
            view: 'news',
            filters: { query: 'care' },
            focus: undefined,
            scroll: undefined,
        });
    });
});
