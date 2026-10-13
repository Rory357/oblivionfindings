import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defaultHero } from './_hero-settings';
import type { HubContent, HubProps } from './_types';
import HubEditor from './editor';

const { request } = vi.hoisted(() => ({ request: vi.fn() }));
vi.mock('./_api', () => ({ hubRequest: request }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    router: { on: () => () => {}, post: vi.fn(), get: vi.fn() },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
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
function editor(draft: HubContent = content, kind = 'home') {
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
            capabilities={{ layout: true, write: true, publish: true }}
            revisions={[]}
            owners={[]}
            sites={[]}
            departments={[]}
            previewData={data}
        />,
    );
}

describe('page designer persistence', () => {
    beforeEach(() => {
        request.mockReset();
        request.mockImplementation((url: string) =>
            Promise.resolve(
                url.endsWith('/presets') ? { presets: [] } : { version: 2 },
            ),
        );
    });
    it('saves a resource checklist and previews its steps without completing them', async () => {
        editor(
            {
                ...content,
                resource_type: 'Checklist',
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
    });
    it('previews and saves hero presentation controls as part of the page draft', async () => {
        editor();
        fireEvent.change(screen.getByLabelText('Text alignment'), {
            target: { value: 'center' },
        });
        fireEvent.change(screen.getByLabelText('Hero icon'), {
            target: { value: 'flag' },
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
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        await waitFor(() =>
            expect(request).toHaveBeenCalledWith(
                '/company-hub/manage/4',
                'PUT',
                expect.objectContaining({
                    content: expect.objectContaining({
                        hero: expect.objectContaining({
                            alignment: 'center',
                            icon: 'flag',
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
        fireEvent.click(screen.getByRole('button', { name: 'Media library' }));
        fireEvent.click(
            await screen.findByRole('button', { name: 'Use Hero.png' }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(
            screen.getByRole('button', { name: 'Save draft' }),
        ).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Undo' })).toBeDisabled();
        expect(request.mock.calls.some(([, method]) => method === 'PUT')).toBe(
            false,
        );
    });
    it('edits a rendered block and saves the changed content to the existing page', async () => {
        editor();
        expect(screen.getByText('A real content preview.')).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Edit Our story' }));
        fireEvent.change(screen.getByLabelText('Story'), {
            target: { value: 'Updated company story.' },
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
    it('can undo and redo a block resize without losing content', () => {
        editor();
        const width = () =>
            screen.getByRole('slider', { name: 'Width of Our story' });
        fireEvent.change(width(), { target: { value: '12' } });
        expect(width()).toHaveValue('12');
        fireEvent.click(screen.getByRole('button', { name: 'Undo' }));
        expect(width()).toHaveValue('6');
        fireEvent.click(screen.getByRole('button', { name: 'Redo' }));
        expect(width()).toHaveValue('12');
        expect(screen.getByText('A real content preview.')).toBeVisible();
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
        fireEvent.change(screen.getByLabelText('Story'), {
            target: { value: 'Keep my work.' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        await waitFor(() =>
            expect(
                screen.getByText(/Someone has updated this draft/),
            ).toBeVisible(),
        );
        expect(screen.getByLabelText('Story')).toHaveValue('Keep my work.');
        expect(screen.getByRole('button', { name: 'Publish' })).toBeDisabled();
    });
});
