import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defaultHero } from './_hero-settings';
import type { HubEvent, HubIdea, HubProps, HubUpdate } from './_types';
import CompanyHub from './index';

const { get, visit, post, recognitionRender } = vi.hoisted(() => ({
    get: vi.fn(),
    visit: vi.fn(),
    post: vi.fn(),
    recognitionRender: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    router: { get, visit, post, remember: vi.fn() },
    useForm: (data: Record<string, unknown>) => ({
        data,
        errors: {},
        processing: false,
        setData: vi.fn(),
        post: vi.fn(),
    }),
    usePage: () => ({ props: { auth: { user: { id: 11 } } } }),
    useRemember: useState,
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
vi.mock('@/components/recognition/recognition-wizard', () => ({
    RecognitionWizard: (props: { open: boolean; defaults?: unknown }) => {
        recognitionRender(props);
        return null;
    },
}));
vi.mock('./_dialogs', () => ({
    BirthdaySettingsDialog: () => null,
    CreateHubPageDialog: ({
        open,
        close,
        initial,
    }: {
        open: boolean;
        close: () => void;
        initial?: { kind: string };
    }) =>
        open ? (
            <section aria-label="Create a Company Hub page">
                <p>{initial?.kind ?? 'news'} setup</p>
                {/* eslint-disable-next-line no-restricted-syntax -- test double for the mocked CreateHubPageDialog */}
                <button onClick={close}>Cancel creation</button>
            </section>
        ) : null,
    HubEventDialog: () => null,
    HubNotificationDialog: () => null,
    ShortcutDialog: () => null,
}));

const props: HubProps = {
    home: {
        title: 'Welcome to Company Hub',
        summary: 'Our people and news',
        hero_label: 'Our company',
        category: 'Company news',
        cover_id: null,
        featured: false,
        audience: { type: 'all', ids: [] },
        comments_enabled: false,
        reactions_enabled: false,
        requires_approval: false,
        show_in_navigation: false,
        navigation_order: 0,
        review_on: null,
        sections: [],
    },
    homeId: null,
    selectedPage: null,
    participation: null,
    birthdaySettings: [],
    pages: [
        {
            id: 1,
            slug: 'company-story',
            kind: 'news',
            title: 'A featured company story',
            summary: 'Read our latest news',
            category: 'Our people',
            cover_url: null,
            featured: true,
            published_at: '2026-10-11T10:00:00Z',
            show_in_navigation: false,
            navigation_order: 0,
        },
    ],
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
};
const featuredProps: HubProps = {
    ...props,
    home: {
        ...props.home,
        sections: [
            {
                id: 'features',
                columns: 1,
                blocks: [
                    {
                        id: 'lead',
                        type: 'featured',
                        title: '',
                        layout: 'mosaic',
                    },
                ],
            },
        ],
    },
    blockData: { lead: { featured: { lead: props.pages[0], supporting: [] } } },
};

describe('Company Hub intranet layout', () => {
    it('keeps the compact saved summary on Home only', () => {
        const { rerender } = render(<CompanyHub {...props} />);
        expect(
            screen.getByRole('region', { name: 'Saved for later' }),
        ).toBeInTheDocument();
        rerender(<CompanyHub {...props} view="news" />);
        expect(
            screen.queryByRole('region', { name: 'Saved for later' }),
        ).not.toBeInTheDocument();
        rerender(<CompanyHub {...props} demo />);
        expect(
            screen.queryByRole('region', { name: 'Saved for later' }),
        ).not.toBeInTheDocument();
    });
    it('closes My hub when its captured internal link navigates', async () => {
        render(<CompanyHub {...props} view="shoutouts" />);
        fireEvent.pointerDown(screen.getByRole('button', { name: 'My hub' }), {
            button: 0,
            ctrlKey: false,
        });
        fireEvent.click(
            await screen.findByRole('menuitem', { name: 'Company updates' }),
        );
        expect(visit).toHaveBeenCalledWith(
            '/company-hub?view=updates',
            expect.objectContaining({ preserveState: true }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('menu')).not.toBeInTheDocument(),
        );
    });
    it('opens an authorised exact idea outside the browse list and removes it when the server withdraws access', () => {
        const selectedIdea: HubIdea = {
            id: 17,
            title: 'An earlier welcome suggestion',
            body: 'Improve the welcome guide.',
            category: 'Our people',
            status: 'open',
            status_note: '',
            version: 1,
            author: 'A colleague',
            created_at: '2026-10-14T00:00:00Z',
            can_remove: false,
            can_update: false,
            support_count: 0,
            supported: false,
            comments: [],
            updates: [],
            owner: null,
            team: null,
        };
        const view = render(
            <CompanyHub
                {...props}
                view="ideas"
                ideas={[]}
                ideaActorId={11}
                ideaId={17}
                selectedIdea={selectedIdea}
            />,
        );
        expect(
            screen.getByRole('dialog', { name: selectedIdea.title }),
        ).toBeVisible();
        view.rerender(
            <CompanyHub
                {...props}
                view="ideas"
                ideas={[]}
                ideaActorId={11}
                ideaId={null}
                selectedIdea={null}
                ideaUnavailable
            />,
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.getByRole('status')).toHaveTextContent(
            'no longer available',
        );
        expect(screen.queryByText(selectedIdea.body)).not.toBeInTheDocument();
    });
    it('opens creation from News in the page canvas beneath the shared hero and returns to the same browse view', () => {
        const view = render(
            <CompanyHub
                {...props}
                view="news"
                capabilities={{ write: true }}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Write a story' }));
        const creation = screen.getByRole('region', {
            name: 'Create a Company Hub page',
        });
        expect(creation.closest('.hub-page-canvas')).toBeVisible();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.getByRole('heading', { name: 'Welcome to Company Hub' }),
        ).toBeVisible();
        expect(
            screen.getByRole('tablist', { name: 'Company Hub views' }),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', { name: 'Write a story' }),
        ).not.toBeInTheDocument();
        expect(
            view.container.querySelector('.hub-page-canvas[hidden]'),
        ).toHaveStyle({ display: 'none' });
        fireEvent.click(
            screen.getByRole('button', { name: 'Cancel creation' }),
        );
        expect(
            screen.queryByRole('region', { name: 'Create a Company Hub page' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Write a story' }),
        ).toBeVisible();
        expect(
            screen.getByRole('heading', { name: 'A featured company story' }),
        ).toBeVisible();
    });
    const event: HubEvent = {
        id: 'event-2-2099-10-15',
        title: 'Garden afternoon',
        start: '2099-10-15T10:00:00+13:00',
        end: '2099-10-15T11:00:00+13:00',
        allDay: false,
        extendedProps: {
            eventId: 2,
            editVersion: 1,
            location: 'Company garden',
            description: 'A little time together.',
            createdBy: 'People & culture',
            myRsvp: null,
            recurring: false,
            site: null,
            recurrenceParentId: null,
            occurrenceDate: '2099-10-15',
        },
    };
    it('opens events on the page and restores the filtered list and keyboard focus', async () => {
        const scroll = vi
            .spyOn(window, 'scrollTo')
            .mockImplementation(() => {});
        render(
            <CompanyHub
                {...props}
                demo
                view="events"
                events={[
                    event,
                    {
                        ...event,
                        id: 'second',
                        title: 'Learning workshop',
                        extendedProps: {
                            ...event.extendedProps,
                            eventId: 3,
                            location: 'Online',
                        },
                    },
                ]}
            />,
        );
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Search events' }),
            { target: { value: 'Garden' } },
        );
        const trigger = screen.getByRole('button', {
            name: /Garden afternoon/,
        });
        trigger.focus();
        fireEvent.click(trigger);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.getByRole('heading', { name: 'Garden afternoon', level: 1 }),
        ).toBeVisible();
        expect(
            screen.getByRole('complementary', { name: 'Event invitation' }),
        ).toBeVisible();
        expect(screen.getByRole('tab', { name: 'News' })).toBeVisible();
        expect(
            screen.queryByRole('textbox', { name: 'Search events' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Back to events' }));
        expect(
            screen.getByRole('textbox', { name: 'Search events' }),
        ).toHaveValue('Garden');
        expect(
            screen.queryByRole('heading', { name: 'Learning workshop' }),
        ).not.toBeInTheDocument();
        await waitFor(() => expect(trigger).toHaveFocus());
        scroll.mockRestore();
    });
    it('uses the current occurrence link for a real event and renders a direct event reader', () => {
        const view = render(
            <CompanyHub {...props} view="events" events={[event]} />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: /Garden afternoon/ }),
        );
        expect(visit).toHaveBeenCalledWith(
            '/company-hub/events/2?date=2099-10-15',
            expect.objectContaining({
                preserveState: true,
                preserveScroll: false,
                onSuccess: expect.any(Function),
            }),
        );
        view.rerender(
            <CompanyHub
                {...props}
                view="events"
                events={[event]}
                selectedEvent={event}
            />,
        );
        expect(
            screen.getByRole('heading', { name: 'Garden afternoon', level: 1 }),
        ).toBeVisible();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
    beforeEach(() => {
        get.mockReset();
        visit.mockReset();
        post.mockReset();
        recognitionRender.mockClear();
        window.history.replaceState({}, '', '/company-hub');
    });
    it.each([
        { can: true, demo: false, past: true, shown: true },
        { can: false, demo: false, past: true, shown: false },
        { can: true, demo: true, past: true, shown: false },
        { can: true, demo: false, past: false, shown: false },
    ])(
        'connects event recap appreciation only with permission outside previews: %j',
        ({ can, demo, past, shown }) => {
            const recap: HubEvent = {
                ...event,
                extendedProps: {
                    ...event.extendedProps,
                    attendeeUserIds: [41, 42],
                    hubContent: {
                        cover: null,
                        host: null,
                        agenda: '',
                        recap: 'An afternoon of good conversations.',
                        gallery: [],
                        story: null,
                        past,
                        editor: null,
                    },
                },
            };
            render(
                <CompanyHub
                    {...props}
                    view="events"
                    demo={demo}
                    events={[recap]}
                    selectedEvent={recap}
                    capabilities={{ recognise: can }}
                />,
            );
            if (!shown) {
                expect(
                    screen.queryByRole('button', { name: 'Thank the team' }),
                ).not.toBeInTheDocument();
                expect(recognitionRender.mock.lastCall?.[0].open).toBe(false);
                return;
            }
            fireEvent.click(
                screen.getByRole('button', { name: 'Thank the team' }),
            );
            expect(recognitionRender.mock.lastCall?.[0]).toMatchObject({
                open: true,
                defaults: undefined,
            });
            expect(
                screen.getByRole('heading', { name: event.title, level: 1 }),
            ).toBeVisible();
            expect(post).not.toHaveBeenCalled();
        },
    );
    it('renders the published page hero and opens that page editor with its own permission', () => {
        const content = {
            ...props.home,
            title: 'Our learning guide',
            cover_id: 42,
            hero: {
                ...defaultHero,
                style: 'image' as const,
                height: 'comfortable' as const,
                alignment: 'center' as const,
                icon: 'newspaper' as const,
                focal_x: 25,
                focal_y: 70,
                overlay: 80,
                action_label: 'Browse learning',
                action_url: '/hr/my/training',
                show_search: false,
                show_summary: false,
            },
        };
        const selectedPage = {
            id: 8,
            slug: 'learning-guide',
            content,
            published_at: '2026-10-11T10:00:00Z',
            can_edit: true,
        };
        const view = render(
            <CompanyHub {...props} selectedPage={selectedPage} />,
        );
        expect(
            screen.getAllByRole('heading', { name: content.title }),
        ).toHaveLength(1);
        expect(
            screen.queryByRole('heading', { name: props.home.title }),
        ).not.toBeInTheDocument();
        expect(screen.queryByRole('search')).not.toBeInTheDocument();
        expect(screen.queryByText(content.summary)).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Browse learning' }),
        ).toHaveAttribute('href', '/hr/my/training');
        const banner = view.container.querySelector('.hub-banner')!;
        expect(banner).toHaveAttribute('data-height', 'comfortable');
        expect(banner).toHaveAttribute('data-alignment', 'center');
        expect(banner).toHaveStyle({ '--hub-shade': '0.8' });
        expect(
            view.container.querySelectorAll('img[src="/company-hub/media/42"]'),
        ).toHaveLength(1);
        expect(banner.querySelector('img')).toHaveStyle({
            objectPosition: '25% 70%',
        });
        expect(
            banner.querySelector('[data-hub-icon="stories"]'),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Edit hero' }));
        expect(visit).toHaveBeenCalledWith(
            '/company-hub/manage/8/edit?panel=hero',
        );
        view.rerender(
            <CompanyHub
                {...props}
                capabilities={{ layout: true }}
                selectedPage={{ ...selectedPage, can_edit: false }}
            />,
        );
        expect(
            screen.queryByRole('button', { name: 'Edit hero' }),
        ).not.toBeInTheDocument();
    });
    it('keeps shared banner settings and summary links across ordinary Hub tabs', () => {
        const home = {
            ...props.home,
            hero: { ...defaultHero, show_metrics: true },
        };
        const heroSummary = [
            {
                label: 'Company stories',
                value: 3,
                url: '/company-hub?view=news',
            },
        ];
        const view = render(
            <CompanyHub {...props} home={home} heroSummary={heroSummary} />,
        );
        for (const tab of ['news', 'saved', 'resources']) {
            view.rerender(
                <CompanyHub
                    {...props}
                    home={home}
                    heroSummary={heroSummary}
                    view={tab}
                />,
            );
            expect(view.container.querySelector('.hub-banner')).toHaveAttribute(
                'data-height',
                'compact',
            );
            expect(
                screen.getByRole('heading', { name: home.title }),
            ).toBeVisible();
            expect(
                within(
                    screen.getByRole('navigation', { name: 'Hub at a glance' }),
                ).getByRole('link', { name: '3 Company stories' }),
            ).toHaveAttribute('href', heroSummary[0].url);
        }
    });
    it('keeps authored featured tiles off Saved and explains how to save items', () => {
        render(<CompanyHub {...featuredProps} view="saved" />);
        expect(
            screen.getByRole('heading', { name: 'Saved for later' }),
        ).toBeVisible();
        expect(
            screen.queryByRole('region', { name: 'Featured stories' }),
        ).not.toBeInTheDocument();
        expect(screen.getByText('Keep something good for later')).toBeVisible();
        expect(
            screen.getByRole('link', { name: 'Explore news' }),
        ).toHaveAttribute('href', '/company-hub?view=news');
        expect(
            within(
                screen.getByRole('navigation', { name: 'Company Hub' }),
            ).getByRole('tab', { name: 'Saved' }),
        ).toHaveAttribute('aria-selected', 'true');
    });
    it('shows an exact saved document once without requiring its guide to be saved', () => {
        const file = {
            ...props.pages[0],
            id: 88,
            title: 'Saved policy PDF',
            document_id: 41,
            filename: 'policy.pdf',
            size: 2048,
            reader_url: '/company-hub/documents/41',
            resource_key: 'file:41',
        };
        render(
            <CompanyHub
                {...props}
                view="saved"
                pages={[]}
                resourceItems={[
                    file,
                    { ...file, resource_key: 'file:duplicate' },
                ]}
                bookmarks={[
                    { subject_type: 'media', subject_id: 41 },
                    { subject_type: 'media', subject_id: 99 },
                ]}
            />,
        );
        expect(
            screen.getAllByRole('link', { name: 'Saved policy PDF' }),
        ).toHaveLength(1);
        expect(
            screen.getByRole('link', { name: 'Saved policy PDF' }),
        ).toHaveAttribute('href', '/company-hub/documents/41');
        expect(
            screen.queryByText('Keep something good for later'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('region', { name: 'Saved documents' }),
        ).toHaveTextContent('policy.pdf');
    });
    it('shows authored feature tiles with published destinations and keeps everyday help separate', () => {
        const view = render(<CompanyHub {...featuredProps} />);
        const hero = within(
            screen.getByRole('region', { name: 'Featured stories' }),
        );
        expect(
            hero.getByRole('link', { name: /A featured company story/ }),
        ).toHaveAttribute('href', '/company-hub/read/company-story');
        expect(
            within(
                screen.getByRole('navigation', { name: 'Everyday help' }),
            ).getByRole('link', { name: 'Find a colleague' }),
        ).toHaveAttribute('href', '/company-hub?view=people');
        expect(
            within(
                screen.getByRole('navigation', { name: 'Everyday help' }),
            ).getByRole('link', { name: 'Find a guide' }),
        ).toHaveAttribute('href', '/company-hub?view=resources');
        view.rerender(
            <CompanyHub
                {...featuredProps}
                home={{ ...props.home, sections: [] }}
            />,
        );
        expect(
            screen.queryByRole('region', { name: 'Featured stories' }),
        ).not.toBeInTheDocument();
    });
    it('resets local update filters when a new main search opens', () => {
        const view = render(<CompanyHub {...props} view="updates" />);
        fireEvent.keyDown(
            screen.getByRole('combobox', { name: 'Update type' }),
            { key: 'ArrowDown' },
        );
        fireEvent.click(screen.getByRole('option', { name: 'Events' }));
        view.rerender(
            <CompanyHub {...props} view="updates" search="Handbook" />,
        );
        expect(
            screen.getByRole('combobox', { name: 'Update type' }),
        ).toHaveTextContent('All types');
    });

    it('keeps current navigation seen cues when an older update request finishes late', async () => {
        const item = (key: string): HubUpdate => ({
            key,
            type: 'news',
            title: key,
            summary: '',
            owner: 'Company team',
            at: '2026-10-15T01:00:00Z',
            url: '/company-hub/read/story',
            topics: [],
            teams: [],
            unread: true,
            following: false,
        });
        let finishOld!: (response: Response) => void;
        const fetcher = vi
            .spyOn(globalThis, 'fetch')
            .mockImplementationOnce(
                () =>
                    new Promise((resolve) => {
                        finishOld = resolve;
                    }),
            )
            .mockResolvedValueOnce(
                new Response(
                    JSON.stringify({ marked: ['new'], batch: 'new-batch' }),
                    { status: 200 },
                ),
            );
        try {
            const view = render(
                <CompanyHub
                    {...props}
                    view="updates"
                    updates={[item('old')]}
                />,
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Mark shown as seen' }),
            );
            view.rerender(
                <CompanyHub
                    {...props}
                    view="updates"
                    search="new"
                    updates={[item('new')]}
                />,
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Mark shown as seen' }),
            );
            await screen.findByText('1 update marked as seen.');
            await act(async () =>
                finishOld(
                    new Response(
                        JSON.stringify({ marked: ['old'], batch: 'old-batch' }),
                        { status: 200 },
                    ),
                ),
            );
            fireEvent.keyDown(screen.getByRole('button', { name: 'My hub' }), {
                key: 'ArrowDown',
            });
            await screen.findByRole('menu');
            expect(
                screen.queryByLabelText('New updates'),
            ).not.toBeInTheDocument();
            fireEvent.keyDown(screen.getByRole('menu'), { key: 'Escape' });
            expect(screen.getByRole('button', { name: 'Undo' })).toBeEnabled();
        } finally {
            fetcher.mockRestore();
        }
    });

    it('opens cross-Hub results for the main hub search', () => {
        render(<CompanyHub {...props} view="resources" />);
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Search Company Hub' }),
            { target: { value: 'Handbook' } },
        );
        fireEvent.submit(screen.getByRole('search'));
        expect(visit).toHaveBeenCalledWith(
            '/company-hub?view=home&search=Handbook',
            expect.objectContaining({
                preserveState: true,
                preserveScroll: false,
                onSuccess: expect.any(Function),
            }),
        );
        expect(
            screen.queryByRole('region', { name: 'Featured stories' }),
        ).not.toBeInTheDocument();
    });

    it('prevents missing-Home creation actions from discarding page setup', () => {
        render(
            <CompanyHub
                {...props}
                capabilities={{ write: true, layout: true }}
            />,
        );
        expect(screen.getByRole('button', { name: 'Edit hero' })).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Create' }));
        expect(
            screen.getByRole('button', { name: 'Design hub', hidden: true }),
        ).toBeDisabled();
        expect(
            screen.queryByRole('button', { name: 'Edit hero' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Design hub', hidden: true }),
        );
        expect(post).not.toHaveBeenCalled();
        expect(
            screen.getByRole('region', { name: 'Create a Company Hub page' }),
        ).toBeVisible();
    });

    it('opens the shared page workspace from publishing', () => {
        render(
            <CompanyHub
                {...props}
                view="publishing"
                capabilities={{ write: true }}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Create page' }));
        expect(
            screen.getByRole('region', { name: 'Create a Company Hub page' }),
        ).toBeVisible();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
});
