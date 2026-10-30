import { router } from '@inertiajs/react';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defaultHero } from './_hero-settings';
import type { HubReadiness } from './_publication-readiness';
import { hubStory } from './_test-fixtures';
import type { HubContent, HubProps, HubStarterLayout } from './_types';
import HubEditor from './editor';

const { request } = vi.hoisted(() => ({ request: vi.fn() }));
vi.mock('./_api', () => ({ hubRequest: request }));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { user: { id: 11 } } } }),
    Head: () => null,
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    router: {
        on: () => () => {},
        post: vi.fn(),
        get: vi.fn(),
        reload: vi.fn(),
    },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => (
        <main id="main-content" tabIndex={-1}>
            {children}
        </main>
    ),
}));
vi.mock('./_dialogs', () => ({ HubEventDialog: () => null }));

const content: HubContent = {
    title: 'Company Hub',
    summary: 'Our shared space',
    category: 'Company news',
    hero_label: 'Our company',
    cover_id: null,
    featured: false,
    audience: { type: 'all', ids: [] },
    comments_enabled: true,
    reactions_enabled: true,
    requires_approval: false,
    show_in_navigation: false,
    navigation_order: 0,
    review_on: null,
    sections: [
        {
            id: 'welcome',
            title: 'Welcome',
            columns: 2,
            blocks: [
                {
                    id: 'body',
                    type: 'text',
                    title: 'Our story',
                    body: 'A real content preview.',
                },
            ],
        },
        { id: 'resources', title: 'Resources', columns: 1, blocks: [] },
    ],
};
const data: HubProps = {
    home: content,
    homeId: 4,
    pages: [],
    navigation: [],
    editablePages: [],
    kudos: [],
    events: [],
    milestones: { birthdays: [], anniversaries: [], new_hires: [] },
    announcements: [],
    capabilities: {},
    employees: [],
    kudosCategories: {},
    kudosImpacts: {},
    shortcuts: [],
    notificationPreferences: {},
    bookmarks: [],
    search: '',
    view: 'home',
    selectedPage: null,
    participation: null,
    birthdaySettings: [],
};
function editor(
    draft: HubContent = content,
    kind = 'home',
    previewData: HubProps = data,
    starterLayouts: HubStarterLayout[] = [],
    permissions: { layout: boolean; demo?: boolean } = { layout: true },
    readiness?: HubReadiness,
) {
    render(
        <HubEditor
            page={{
                id: 4,
                kind,
                slug: 'home',
                draft: structuredClone(draft),
                draft_version: 1,
                owner_id: 1,
                archived_at: null,
            }}
            capabilities={{
                layout: permissions.layout,
                write: true,
                publish: true,
            }}
            demo={permissions.demo}
            revisions={[]}
            owners={[]}
            sites={[]}
            departments={[]}
            previewData={previewData}
            starterLayouts={starterLayouts}
            readiness={readiness}
        />,
    );
}

describe('page designer persistence', () => {
    beforeEach(() => {
        request.mockReset();
        vi.mocked(router.post).mockClear();
        vi.mocked(router.reload).mockClear();
        request.mockImplementation((url: string) =>
            Promise.resolve(
                url.endsWith('/presets') ? { presets: [] } : { version: 2 },
            ),
        );
    });
    const ready: HubReadiness = {
        actor_id: 1,
        page_id: 4,
        version: 1,
        content_ready: true,
        editable: true,
        blocked_reason: null,
        blocking_revision_id: null,
        issues: [],
    };
    it('removes the current-draft entry after publication without a version increment', () => {
        const base = {
            page: {
                id: 4,
                kind: 'home',
                slug: 'home',
                draft: content,
                draft_version: 1,
                owner_id: 1,
                archived_at: null,
            },
            capabilities: { layout: true, write: true, publish: true },
            revisions: [],
            owners: [],
            sites: [],
            departments: [],
            previewData: data,
            readiness: ready,
            draftRecovery: {
                actor_id: 1,
                page_id: 4,
                version: 1,
                has_saved_draft: true,
                can_discard: true,
                blocked_reason: null,
                recovery: null,
            },
        };
        const view = render(<HubEditor {...base} />);
        fireEvent.click(screen.getByRole('button', { name: 'Versions' }));
        expect(
            screen.getByRole('button', { name: 'View saved draft' }),
        ).toBeVisible();
        view.rerender(
            <HubEditor
                {...base}
                draftRecovery={{
                    ...base.draftRecovery,
                    has_saved_draft: false,
                    can_discard: false,
                    blocked_reason: 'no_saved_draft',
                }}
            />,
        );
        expect(
            screen.queryByRole('button', { name: 'View saved draft' }),
        ).not.toBeInTheDocument();
    });
    it.each(['review', 'schedule'] as const)(
        'opens fresh saved history after uncertain %s recovery remounts the editor',
        async (reason) => {
            const base = {
                page: {
                    id: 4,
                    kind: 'home',
                    slug: 'home',
                    draft: structuredClone(content),
                    draft_version: 1,
                    owner_id: 1,
                    archived_at: null,
                },
                capabilities: { layout: true, write: true, publish: true },
                revisions: [],
                owners: [],
                sites: [],
                departments: [],
                previewData: data,
                readiness: ready,
            };
            const rendered = render(<HubEditor {...base} />);
            fireEvent.click(
                screen.getByRole('button', { name: 'Review & publish' }),
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Publish now' }),
            );
            fireEvent.click(screen.getByRole('button', { name: 'Confirm' }));
            const completion = vi.mocked(router.post).mock.calls[0][2];
            act(() => completion?.onFinish?.({} as never));
            fireEvent.click(
                screen.getByRole('button', { name: 'Check saved versions' }),
            );
            expect(router.reload).toHaveBeenCalledWith(
                expect.objectContaining({
                    only: expect.arrayContaining([
                        'page',
                        'revisions',
                        'readiness',
                    ]),
                }),
            );
            rendered.rerender(
                <HubEditor
                    {...base}
                    readiness={{
                        ...ready,
                        editable: false,
                        blocked_reason: reason,
                        blocking_revision_id: 20,
                    }}
                    revisions={[
                        {
                            id: 20,
                            state:
                                reason === 'review' ? 'submitted' : 'approved',
                            draft_version: 1,
                            created_at: '2026-10-14T00:00:00Z',
                            publish_at:
                                reason === 'schedule'
                                    ? '2099-10-13T20:00:00.000Z'
                                    : null,
                        },
                    ]}
                />,
            );
            const refresh = vi.mocked(router.reload).mock.calls[0][0];
            await act(async () => {
                refresh?.onSuccess?.({} as never);
                refresh?.onFinish?.({} as never);
            });
            expect(
                screen.getByRole('heading', {
                    name: 'Version history & review',
                }),
            ).toBeVisible();
            expect(
                screen.queryByRole('region', { name: 'Publication workspace' }),
            ).not.toBeInTheDocument();
            expect(
                screen.getByRole('button', {
                    name:
                        reason === 'review' ? 'Review version' : 'View version',
                }),
            ).toBeVisible();
        },
    );
    it('opens publication beneath the shared hero and sends nothing before explicit confirmation', async () => {
        editor(content, 'home', data, [], { layout: true }, ready);
        fireEvent.click(
            screen.getByRole('button', { name: 'Review & publish' }),
        );
        expect(
            screen.getByRole('region', { name: 'Publication workspace' }),
        ).toBeVisible();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('complementary', { name: 'Designer settings' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByLabelText('Publication content preview'),
        ).toHaveTextContent('A real content preview.');
        expect(router.post).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Back to draft' }));
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Review & publish' }),
            ).toHaveFocus(),
        );
        expect(
            screen.getByRole('complementary', { name: 'Designer settings' }),
        ).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Review & publish' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Publish now' }));
        expect(router.post).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Confirm' }));
        expect(router.post).toHaveBeenCalledWith(
            '/company-hub/manage/4/publish',
            { version: 1, publish_at: null, expires_at: null },
            expect.any(Object),
        );
    });
    it('unlocks editing after withdrawing a review without requiring a new draft version', () => {
        const tree = (readiness: HubReadiness) => (
            <HubEditor
                page={{
                    id: 4,
                    kind: 'home',
                    slug: 'home',
                    draft: structuredClone(content),
                    draft_version: 1,
                    owner_id: 1,
                    archived_at: null,
                }}
                capabilities={{ layout: true, write: true, publish: true }}
                revisions={[]}
                owners={[]}
                sites={[]}
                departments={[]}
                previewData={data}
                readiness={readiness}
            />
        );
        const { rerender } = render(
            tree({
                ...ready,
                editable: false,
                blocked_reason: 'review',
                blocking_revision_id: 22,
            }),
        );
        expect(screen.getByRole('button', { name: 'Design' })).toBeDisabled();
        rerender(tree(ready));
        expect(screen.getByRole('button', { name: 'Design' })).toBeEnabled();
        expect(
            screen.getByRole('complementary', { name: 'Designer settings' }),
        ).toBeVisible();
        expect(
            screen.getByLabelText('Title', { selector: 'input' }),
        ).toHaveValue(content.title);
    });
    it('keeps unfinished authored blocks saveable and prevents publication until saved content is ready', async () => {
        const unfinished = {
            ...content,
            sections: [
                {
                    ...content.sections[0],
                    blocks: [{ ...content.sections[0].blocks[0], body: '' }],
                },
            ],
        };
        const pending = {
            ...ready,
            content_ready: false,
            issues: [
                {
                    section_id: 'welcome',
                    block_id: 'body',
                    field: 'sections.0.blocks.0.body',
                    code: 'block_body',
                    message:
                        'Add the visible block content, or hide this block.',
                },
            ],
        };
        request.mockImplementation((url: string) =>
            Promise.resolve(
                url.endsWith('/presets')
                    ? { presets: [] }
                    : { version: 2, readiness: { ...pending, version: 2 } },
            ),
        );
        editor(unfinished, 'home', data, [], { layout: true }, pending);
        expect(
            screen.queryByRole('button', { name: 'Edit block' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'View 1 check' }));
        expect(
            screen.getByRole('button', { name: 'Review & publish' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('region', { name: 'Publication readiness' }),
        ).toHaveTextContent('Your draft is saved.');
        fireEvent.change(
            screen.getByLabelText('Title', { selector: 'input' }),
            { target: { value: 'A work in progress' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        await waitFor(() =>
            expect(request).toHaveBeenCalledWith(
                '/company-hub/manage/4',
                'PUT',
                expect.objectContaining({
                    content: expect.objectContaining({
                        title: 'A work in progress',
                    }),
                }),
            ),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('region', { name: 'Publication readiness' }),
            ).toHaveTextContent('Add the visible block content'),
        );
        expect(
            screen.getByRole('button', { name: 'Review & publish' }),
        ).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Edit block' }));
        expect(screen.getByLabelText('Block width')).toBeVisible();
    });
    it.each(['review', 'schedule'] as const)(
        'keeps a %s draft in preview while leaving its explicit review controls available',
        (reason) => {
            editor(
                content,
                'home',
                data,
                [],
                { layout: true },
                {
                    ...ready,
                    editable: false,
                    blocked_reason: reason,
                    blocking_revision_id: 22,
                },
            );
            expect(
                screen.getByRole('button', { name: 'Review & publish' }),
            ).toBeDisabled();
            expect(
                screen.getByRole('button', { name: 'Design' }),
            ).toBeDisabled();
            expect(
                screen.getByRole('button', { name: 'Desktop preview' }),
            ).toHaveAttribute('aria-pressed', 'true');
            expect(
                screen.queryByRole('complementary', {
                    name: 'Designer settings',
                }),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByRole('button', { name: 'Edit hero' }),
            ).not.toBeInTheDocument();
            fireEvent.click(
                screen.getByRole('button', {
                    name: 'View review and schedule',
                }),
            );
            expect(
                screen.getByRole('heading', {
                    name: 'Version history & review',
                }),
            ).toBeVisible();
        },
    );
    it.each([
        { layout: false, demo: false },
        { layout: true, demo: true },
    ])(
        'does not expose shared tab writes without permission or in a showcase: %j',
        (permissions) => {
            editor(content, 'home', data, [], permissions);
            expect(
                screen.queryByRole('button', { name: 'Edit tabs' }),
            ).not.toBeInTheDocument();
        },
    );
    it('opens the shared tab designer directly and preserves unsaved page content when it closes', async () => {
        const home = {
            id: 'home',
            type: 'view',
            label: 'Home',
            target: 'home',
            parent: '',
            hidden: false,
        };
        request.mockImplementation((url: string) =>
            Promise.resolve(
                url.endsWith('/presets')
                    ? { presets: [] }
                    : url.endsWith('/shared-navigation')
                      ? {
                            version: 3,
                            has_draft: false,
                            published_at: null,
                            draft: [home],
                            published: [home],
                            pages: [],
                            views: { home: 'Home' },
                            can_publish: true,
                        }
                      : { version: 2 },
            ),
        );
        editor();
        fireEvent.change(screen.getByLabelText('Title', { exact: true }), {
            target: { value: 'Keep this page title' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Edit tabs' }));
        const tabs = await screen.findByRole('region', {
            name: 'Edit Company Hub tabs',
        });
        await screen.findByRole('button', { name: 'Add tab' });
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.getByText('A real content preview.')).toBeVisible();
        expect(
            within(
                screen.getByRole('complementary', {
                    name: 'Designer settings',
                }),
            ).getByLabelText('Navigation label'),
        ).toHaveValue('Home');
        expect(
            request.mock.calls.some(([url]) => url === '/company-hub/manage/4'),
        ).toBe(false);
        fireEvent.click(
            within(tabs).getByRole('button', { name: 'Done editing tabs' }),
        );
        expect(screen.getByLabelText('Title', { exact: true })).toHaveValue(
            'Keep this page title',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        await waitFor(() =>
            expect(request).toHaveBeenCalledWith(
                '/company-hub/manage/4',
                'PUT',
                expect.objectContaining({
                    version: 1,
                    content: expect.objectContaining({
                        title: 'Keep this page title',
                    }),
                }),
            ),
        );
    });
    it('keeps automatic column sizing when only the height is resized', async () => {
        editor();
        fireEvent.keyDown(
            screen.getByRole('button', { name: 'Resize height of Our story' }),
            { key: 'ArrowDown' },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        await waitFor(() => {
            const savedBlock = request.mock.calls.find(
                ([, method]) => method === 'PUT',
            )?.[2].content.sections[0].blocks[0];
            expect(savedBlock?.min_height).toBe(36);
            expect(savedBlock).not.toHaveProperty('width');
        });
    });
    it('resets sections without replacing the hero or audience, and can undo the complete layout change', async () => {
        const draft = {
            ...content,
            hero: structuredClone(defaultHero),
            audience: { type: 'sites' as const, ids: [8] },
        };
        editor(draft, 'home', data, [
            { key: 'blank', label: 'Blank page', sections: [] },
        ]);
        fireEvent.click(screen.getByRole('button', { name: 'Draft options' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Choose a starting layout' }),
        );
        fireEvent.click(screen.getByRole('radio', { name: /Blank page/ }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Use this layout' }),
        );
        expect(
            screen.queryByRole('button', { name: 'Edit Our story' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        await waitFor(() =>
            expect(request).toHaveBeenCalledWith(
                '/company-hub/manage/4',
                'PUT',
                expect.objectContaining({
                    content: expect.objectContaining({
                        title: draft.title,
                        hero: draft.hero,
                        audience: draft.audience,
                        sections: [],
                    }),
                }),
            ),
        );
        await waitFor(() =>
            expect(screen.getByRole('button', { name: 'Undo' })).toBeEnabled(),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Undo' }));
        expect(
            screen.getByRole('button', { name: 'Edit Our story' }),
        ).toBeVisible();
    });
    it('edits an authored featured story and keeps its layout and message in preview and the saved draft', async () => {
        editor(
            {
                ...content,
                sections: [
                    {
                        id: 'features',
                        columns: 1,
                        blocks: [
                            {
                                id: 'lead',
                                type: 'featured',
                                title: '',
                                story_id: null,
                                layout: 'mosaic',
                            },
                        ],
                    },
                ],
            },
            'home',
            {
                ...data,
                pages: [
                    hubStory,
                    {
                        ...hubStory,
                        id: 12,
                        slug: 'team-story',
                        title: 'Our team story',
                    },
                ],
            },
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Edit Featured stories' }),
        );
        fireEvent.keyDown(
            screen.getByRole('combobox', { name: 'Featured story' }),
            { key: 'ArrowDown' },
        );
        fireEvent.click(
            await screen.findByRole('option', { name: 'Our team story' }),
        );
        fireEvent.change(screen.getByLabelText('Featured message'), {
            target: { value: 'A message from our people.' },
        });
        fireEvent.keyDown(
            screen.getByRole('combobox', { name: 'Presentation' }),
            { key: 'ArrowDown' },
        );
        fireEvent.click(
            await screen.findByRole('option', { name: 'One featured story' }),
        );
        fireEvent.keyDown(
            screen.getByRole('combobox', { name: 'Block background' }),
            { key: 'ArrowDown' },
        );
        fireEvent.click(
            await screen.findByRole('option', { name: 'Soft tint' }),
        );
        expect(
            screen.getByText('A message from our people.', { selector: 'p' }),
        ).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Desktop preview' }),
        );
        const feature = screen.getByRole('region', {
            name: 'Featured stories',
        });
        expect(feature.querySelector('.hub-authored-feature')).toHaveAttribute(
            'data-layout',
            'single',
        );
        expect(feature.querySelectorAll('a')).toHaveLength(1);
        expect(
            screen.getByRole('heading', { name: 'Our team story' }),
        ).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        await waitFor(() =>
            expect(request).toHaveBeenCalledWith(
                '/company-hub/manage/4',
                'PUT',
                expect.objectContaining({
                    content: expect.objectContaining({
                        sections: [
                            {
                                id: 'features',
                                columns: 1,
                                blocks: [
                                    expect.objectContaining({
                                        id: 'lead',
                                        type: 'featured',
                                        story_id: 12,
                                        title: 'Our team story',
                                        body: 'A message from our people.',
                                        layout: 'single',
                                        tone: 'soft',
                                    }),
                                ],
                            },
                        ],
                    }),
                }),
            ),
        );
    });
    it('shows the four team-space views in the actual desktop preview', () => {
        editor(content, 'department');
        fireEvent.click(
            screen.getByRole('button', { name: 'Desktop preview' }),
        );
        expect(
            screen.getByRole('tablist', { name: 'Department space views' }),
        ).toBeVisible();
        fireEvent.click(screen.getByRole('tab', { name: /Our team/ }));
        expect(
            screen.getByRole('region', { name: 'Our team content' }),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', { name: 'New update' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Follow team' }),
        ).not.toBeInTheDocument();
    });
    it('changes the resource type through the shared menu and saves the canonical value', async () => {
        editor({ ...content, resource_type: 'Guide' }, 'knowledge');
        const control = screen.getByRole('combobox', { name: 'Resource type' });
        expect(control).toHaveTextContent('Guide');
        fireEvent.keyDown(control, { key: 'ArrowDown' });
        fireEvent.click(await screen.findByRole('option', { name: 'Process' }));
        expect(control).toHaveTextContent('Process');
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        await waitFor(() =>
            expect(request).toHaveBeenCalledWith(
                '/company-hub/manage/4',
                'PUT',
                expect.objectContaining({
                    content: expect.objectContaining({
                        resource_type: 'Process',
                    }),
                }),
            ),
        );
    });
    it.each(['Checklist', 'Process'])(
        'saves %s resource steps and previews them without completing them',
        async (resourceType) => {
            editor(
                {
                    ...content,
                    resource_type: resourceType,
                    checklist: [{ id: 'first', title: 'Meet your buddy' }],
                },
                'knowledge',
            );
            fireEvent.change(screen.getByRole('textbox', { name: 'Step 1' }), {
                target: { value: 'Meet your team buddy' },
            });
            fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
            await waitFor(() =>
                expect(request).toHaveBeenCalledWith(
                    '/company-hub/manage/4',
                    'PUT',
                    expect.objectContaining({
                        content: expect.objectContaining({
                            checklist: [
                                { id: 'first', title: 'Meet your team buddy' },
                            ],
                        }),
                    }),
                ),
            );
            await waitFor(() =>
                expect(screen.getByText('Draft saved')).toBeVisible(),
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Desktop preview' }),
            );
            expect(
                screen.getByRole('checkbox', { name: 'Meet your team buddy' }),
            ).toBeDisabled();
            expect(
                screen.getByRole('navigation', { name: 'Guide contents' }),
            ).toBeVisible();
            expect(
                request.mock.calls.some(([url]) =>
                    String(url).endsWith('/checklist'),
                ),
            ).toBe(false);
        },
    );
    it('previews and saves hero presentation controls as part of the page draft', async () => {
        editor();
        fireEvent.click(screen.getByRole('button', { name: 'Edit hero' }));
        fireEvent.click(screen.getByRole('radio', { name: 'Centre' }));
        fireEvent.click(screen.getByRole('radio', { name: 'People' }));
        fireEvent.click(screen.getByRole('radio', { name: 'Cover image' }));
        fireEvent.change(screen.getByRole('slider', { name: /Image shade/ }), {
            target: { value: '20' },
        });
        fireEvent.click(screen.getByLabelText('Show Hub search'));
        fireEvent.click(screen.getByLabelText('Show description'));
        fireEvent.click(screen.getByLabelText('Show Hub summary links'));
        expect(screen.queryByRole('search')).not.toBeInTheDocument();
        expect(
            screen
                .getByRole('heading', { name: 'Company Hub' })
                .closest('header'),
        ).not.toHaveTextContent('Our shared space');
        fireEvent.click(screen.getByRole('button', { name: 'Apply to draft' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        await waitFor(() =>
            expect(request).toHaveBeenCalledWith(
                '/company-hub/manage/4',
                'PUT',
                expect.objectContaining({
                    content: expect.objectContaining({
                        hero: expect.objectContaining({
                            alignment: 'center',
                            icon: 'users',
                            overlay: 20,
                            show_search: false,
                            show_summary: false,
                            show_metrics: true,
                        }),
                    }),
                }),
            ),
        );
        await waitFor(() =>
            expect(screen.getByText('Draft saved')).toBeVisible(),
        );
    });
    it('does not dirty a page when the editor reselects its existing hero image', async () => {
        const media = {
            id: 7,
            name: 'Hero.png',
            mime: 'image/png',
            size: 50,
            url: '/company-hub/media/7',
            hub_page_id: 4,
            page_title: 'Company Hub',
            alt: '',
            album_id: null,
            version: 1,
            crop: null,
        };
        request.mockImplementation((url: string) =>
            Promise.resolve(
                url.includes('/media?')
                    ? {
                          media: [media],
                          albums: [],
                          pages: [{ id: 4, title: 'Company Hub' }],
                          total: 1,
                          next_page: null,
                      }
                    : url.endsWith('/reuse')
                      ? media
                      : { presets: [] },
            ),
        );
        editor({
            ...content,
            cover_id: 7,
            hero: { ...defaultHero, style: 'image' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Edit hero' }));
        fireEvent.click(screen.getByRole('button', { name: 'Media library' }));
        fireEvent.click(
            await screen.findByRole('button', { name: 'Use Hero.png' }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(
            screen.getByRole('button', { name: 'Apply to draft' }),
        ).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(
            screen.getByRole('button', { name: 'Save draft' }),
        ).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Undo' })).toBeDisabled();
        expect(request.mock.calls.some(([, method]) => method === 'PUT')).toBe(
            false,
        );
    });
    it('keeps hero exploration out of autosave, supports reset and protects an unapplied preview', async () => {
        vi.useFakeTimers();
        try {
            editor();
            fireEvent.click(
                screen.getByRole('button', { name: 'Edit hero banner' }),
            );
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
            fireEvent.change(screen.getByLabelText('Title'), {
                target: { value: 'A brighter welcome' },
            });
            await act(async () => {
                await vi.advanceTimersByTimeAsync(1200);
            });
            expect(
                screen.getByRole('heading', { name: 'A brighter welcome' }),
            ).toBeVisible();
            expect(
                request.mock.calls.filter(([, method]) => method === 'PUT'),
            ).toHaveLength(0);
            const unload = new Event('beforeunload', { cancelable: true });
            window.dispatchEvent(unload);
            expect(unload.defaultPrevented).toBe(true);
            fireEvent.click(
                screen.getByRole('button', { name: 'Reset preview' }),
            );
            expect(screen.getByLabelText('Title')).toHaveValue('Company Hub');
            expect(
                screen.getByRole('button', { name: 'Apply to draft' }),
            ).toBeDisabled();
            fireEvent.change(screen.getByLabelText('Title'), {
                target: { value: 'Another direction' },
            });
            fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
            fireEvent.click(
                screen.getByRole('button', { name: 'Keep editing' }),
            );
            expect(screen.getByLabelText('Title')).toHaveValue(
                'Another direction',
            );
            fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
            fireEvent.click(
                screen.getByRole('button', { name: 'Discard preview' }),
            );
            expect(
                screen.queryByRole('region', { name: 'Hero designer' }),
            ).not.toBeInTheDocument();
            expect(screen.getByLabelText('Title')).toHaveValue('Company Hub');
            expect(screen.getByRole('button', { name: 'Undo' })).toBeDisabled();
            expect(
                request.mock.calls.filter(([, method]) => method === 'PUT'),
            ).toHaveLength(0);
        } finally {
            vi.useRealTimers();
        }
    });
    it('applies all hero changes as one undo step while preserving earlier page changes', async () => {
        editor();
        fireEvent.change(screen.getByLabelText('Introduction'), {
            target: { value: 'A page change before the preview.' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Edit hero' }));
        expect(
            screen.getByRole('heading', {
                name: 'A welcome that feels like your company.',
            }),
        ).toHaveFocus();
        fireEvent.change(screen.getByLabelText('Title'), {
            target: { value: 'Our shared home' },
        });
        fireEvent.click(screen.getByRole('radio', { name: 'Centre' }));
        fireEvent.click(screen.getByRole('button', { name: 'Apply to draft' }));
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Edit hero' }),
            ).toHaveFocus(),
        );
        expect(
            screen.queryByRole('region', { name: 'Hero designer' }),
        ).not.toBeInTheDocument();
        expect(screen.getByLabelText('Title')).toHaveValue('Our shared home');
        fireEvent.click(screen.getByRole('button', { name: 'Undo' }));
        expect(screen.getByLabelText('Title')).toHaveValue('Company Hub');
        expect(screen.getByLabelText('Introduction')).toHaveValue(
            'A page change before the preview.',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Redo' }));
        expect(screen.getByLabelText('Title')).toHaveValue('Our shared home');
        fireEvent.click(screen.getByRole('button', { name: 'Edit hero' }));
        expect(screen.getByRole('radio', { name: 'Centre' })).toBeChecked();
    });
    it('hides and restores a whole section without showing its individually hidden blocks', async () => {
        const draft = structuredClone(content);
        draft.sections[0].blocks.push({
            id: 'hidden-note',
            type: 'text',
            title: 'Private planning note',
            body: 'Keep this prepared.',
            hidden: true,
        });
        editor(draft);
        const welcome = document.getElementById(
            'hub-designer-section-welcome',
        )!;
        fireEvent.click(
            within(welcome).getByRole('button', { name: 'Hide section' }),
        );
        expect(within(welcome).getByText('Hidden from staff')).toBeVisible();
        expect(
            within(welcome).getByRole('button', { name: 'Edit Our story' }),
        ).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Desktop preview' }),
        );
        expect(
            screen.queryByText('A real content preview.'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Keep this prepared.'),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Design' }));
        fireEvent.click(screen.getByRole('button', { name: 'Show section' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Desktop preview' }),
        );
        expect(screen.getByText('A real content preview.')).toBeVisible();
        expect(
            screen.queryByText('Keep this prepared.'),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        await waitFor(() =>
            expect(
                request.mock.calls.find(([, method]) => method === 'PUT')?.[2]
                    .content.sections[0],
            ).toMatchObject({
                hidden: false,
                blocks: [{ id: 'body' }, { id: 'hidden-note', hidden: true }],
            }),
        );
    });
    it('confirms section removal and restores all its blocks with one undo', async () => {
        editor();
        const remove = () =>
            fireEvent.click(
                within(
                    document.getElementById('hub-designer-section-welcome')!,
                ).getByRole('button', { name: 'Remove section' }),
            );
        remove();
        const dialog = screen.getByRole('dialog', { name: 'Remove Welcome?' });
        expect(
            within(dialog).getByText(/section and its 1 block/),
        ).toBeVisible();
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Keep section' }),
        );
        expect(
            screen.getByRole('button', { name: 'Edit Our story' }),
        ).toBeVisible();
        expect(screen.getByRole('button', { name: 'Undo' })).toBeDisabled();
        remove();
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove section and blocks' }),
        );
        await waitFor(() =>
            expect(screen.getByRole('button', { name: 'Undo' })).toHaveFocus(),
        );
        expect(
            screen.queryByRole('button', { name: 'Edit Our story' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Undo' }));
        expect(
            screen.getByRole('button', { name: 'Edit Our story' }),
        ).toBeVisible();
        expect(screen.getByText('A real content preview.')).toBeVisible();
    });
    it('edits a rendered block and saves the changed content to the existing page', async () => {
        editor();
        expect(screen.getByText('A real content preview.')).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Edit Our story' }));
        fireEvent.input(screen.getByLabelText('Story'), {
            target: { textContent: 'Updated company story.' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        await waitFor(() =>
            expect(request).toHaveBeenCalledWith(
                '/company-hub/manage/4',
                'PUT',
                expect.anything(),
            ),
        );
        const saved = request.mock.calls.find((call) => call[1] === 'PUT')!;
        expect(saved[0]).toBe('/company-hub/manage/4');
        expect(saved[2].content.sections[0].blocks[0].body).toBe(
            'Updated company story.',
        );
        await waitFor(() =>
            expect(screen.getByText('Draft saved')).toBeVisible(),
        );
    });
    it('can undo and redo a block resize and save its dimensions without losing content', async () => {
        editor();
        const handle = screen.getByRole('button', { name: 'Resize Our story' });
        fireEvent.keyDown(handle, { key: 'ArrowRight' });
        expect(screen.getByLabelText('Size of Our story')).toHaveTextContent(
            'Two thirds',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Undo' }));
        expect(screen.getByLabelText('Size of Our story')).toHaveTextContent(
            'Half width',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Redo' }));
        expect(screen.getByLabelText('Size of Our story')).toHaveTextContent(
            'Two thirds',
        );
        expect(
            screen
                .getAllByText('A real content preview.', { selector: 'p' })
                .find((element) => !element.closest('[contenteditable]'))!,
        ).toBeVisible();
        fireEvent.keyDown(handle, { key: 'ArrowDown' });
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        await waitFor(() =>
            expect(
                request.mock.calls.find(([, method]) => method === 'PUT')?.[2]
                    .content.sections[0].blocks[0],
            ).toMatchObject({
                width: 8,
                min_height: 36,
                body: 'A real content preview.',
            }),
        );
    });
    it('shows hidden blocks in the outline and jumps to their actual settings', async () => {
        editor({
            ...content,
            sections: [
                {
                    ...content.sections[0],
                    blocks: [
                        { ...content.sections[0].blocks[0], hidden: true },
                    ],
                },
            ],
        });
        fireEvent.click(screen.getByRole('button', { name: 'Page outline' }));
        const outline = screen.getByRole('complementary', {
            name: 'Designer settings',
        });
        expect(within(outline).getByText('1 section · 1 block')).toBeVisible();
        fireEvent.click(
            within(outline).getByRole('button', {
                name: 'Go to Our story (hidden from staff)',
            }),
        );
        expect(screen.getByLabelText('Story')).toHaveTextContent(
            'A real content preview.',
        );
        expect(
            screen.getByRole('button', { name: /^Settings$/ }),
        ).toHaveAttribute('aria-pressed', 'true');
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Edit Our story' }),
            ).toHaveFocus(),
        );
        expect(
            screen.getByLabelText('Show this block to staff'),
        ).not.toBeChecked();
    });
    it('adds an illustrated library choice to the chosen section and keeps the new block undoable', async () => {
        editor();
        fireEvent.click(
            screen.getByRole('button', { name: 'Add block to Resources' }),
        );
        const library = screen.getByRole('region', { name: 'Block library' });
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        fireEvent.click(
            within(library).getByRole('button', {
                name: 'Everyday essentials',
            }),
        );
        expect(
            within(library).queryByRole('button', {
                name: 'Configure Featured stories',
            }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            within(library).getByRole('button', {
                name: 'Configure Staff services',
            }),
        );
        expect(
            within(library).getByRole('heading', {
                name: 'Configure Staff services',
            }),
        ).toBeVisible();
        fireEvent.click(
            within(library).getByRole('button', { name: 'Add to page' }),
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        const destination = document.getElementById(
            'hub-designer-section-resources',
        )!;
        expect(
            within(destination).getByRole('button', {
                name: 'Edit Staff services',
            }),
        ).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Undo' }));
        expect(
            screen.queryByRole('button', { name: 'Edit Staff services' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Redo' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        await waitFor(() =>
            expect(
                request.mock.calls.find(([, method]) => method === 'PUT')?.[2]
                    .content.sections[1].blocks[0].type,
            ).toBe('services'),
        );
    });
    it('keeps uninserted setup out of saved page content and protects browser departure', () => {
        editor();
        fireEvent.click(
            screen.getByRole('button', { name: 'Add block to Resources' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Configure Text & story' }),
        );
        fireEvent.input(screen.getByLabelText('Story'), {
            target: { textContent: 'An unfinished introduction.' },
        });
        const unload = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(unload);
        expect(unload.defaultPrevented).toBe(true);
        fireEvent.click(screen.getByRole('button', { name: 'Close library' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Keep configuring' }),
        );
        expect(screen.getByLabelText('Story')).toHaveTextContent(
            'An unfinished introduction.',
        );
        expect(
            request.mock.calls.filter(([, method]) => method === 'PUT'),
        ).toHaveLength(0);
        fireEvent.click(screen.getByRole('button', { name: 'Close library' }));
        fireEvent.click(screen.getByRole('button', { name: 'Discard setup' }));
        expect(
            screen.queryByRole('region', { name: 'Block library' }),
        ).not.toBeInTheDocument();
        expect(
            document.getElementById('hub-designer-section-resources'),
        ).not.toHaveTextContent('An unfinished introduction.');
    });
    it('resizes from the corner with the keyboard and supports undo shortcuts outside text fields', () => {
        editor();
        const handle = screen.getByRole('button', { name: 'Resize Our story' });
        fireEvent.keyDown(handle, { key: 'ArrowRight' });
        expect(screen.getByLabelText('Size of Our story')).toHaveTextContent(
            'Two thirds',
        );
        fireEvent.keyDown(document.body, { key: 'z', ctrlKey: true });
        expect(screen.getByLabelText('Size of Our story')).toHaveTextContent(
            'Half width',
        );
        fireEvent.keyDown(document.body, {
            key: 'Z',
            metaKey: true,
            shiftKey: true,
        });
        expect(screen.getByLabelText('Size of Our story')).toHaveTextContent(
            'Two thirds',
        );
        const story = screen.getByLabelText('Story');
        fireEvent.input(story, { target: { textContent: 'A new message' } });
        fireEvent.keyDown(story, { key: 'z', ctrlKey: true });
        expect(story).toHaveTextContent('A new message');
        expect(screen.getByLabelText('Size of Our story')).toHaveTextContent(
            'Two thirds',
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Designer shortcuts' }),
        );
        const help = screen.getByRole('dialog', {
            name: 'A few useful shortcuts',
        });
        expect(within(help).getByText(/Escape cancels a drag/)).toBeVisible();
        fireEvent.click(within(help).getByRole('button', { name: 'Got it' }));
    });
    it('keeps a hidden block editable while the staff preview omits it, and undo restores its visibility', () => {
        editor();
        fireEvent.click(screen.getByRole('button', { name: 'Edit Our story' }));
        fireEvent.change(
            screen.getByRole('spinbutton', { name: /Minimum block height/ }),
            {
                target: { value: '360' },
            },
        );
        fireEvent.click(screen.getByLabelText('Show this block to staff'));
        expect(
            screen.getByText('Hidden from staff · kept in your design'),
        ).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Desktop preview' }),
        );
        expect(
            screen.queryByText('A real content preview.'),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Undo' }));
        expect(screen.getByText('A real content preview.')).toBeVisible();
        expect(screen.getByRole('region', { name: 'Our story' })).toHaveStyle({
            minHeight: '360px',
        });
    });
    it('keeps an unsaved change visible when the server rejects a stale draft', async () => {
        request.mockImplementation((url: string) =>
            url.endsWith('/presets')
                ? Promise.resolve({ presets: [] })
                : Promise.reject(
                      new Error(
                          'Someone has updated this draft. Reload before saving.',
                      ),
                  ),
        );
        editor();
        fireEvent.click(screen.getByRole('button', { name: 'Edit Our story' }));
        fireEvent.input(screen.getByLabelText('Story'), {
            target: { textContent: 'Keep my work.' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        await waitFor(() =>
            expect(
                screen.getByText(/Someone has updated this draft/),
            ).toBeVisible(),
        );
        expect(screen.getByLabelText('Story')).toHaveTextContent(
            'Keep my work.',
        );
        expect(
            screen.getByRole('button', { name: 'Review & publish' }),
        ).toBeDisabled();
    });
});
