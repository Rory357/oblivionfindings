import { fireEvent, render, screen, within } from '@testing-library/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defaultHero } from './_hero-settings';
import type { HubProps } from './_types';
import CompanyHub from './index';

const { get, visit } = vi.hoisted(() => ({ get: vi.fn(), visit: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    router: { get, visit, post: vi.fn() },
    useForm: vi.fn(),
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
vi.mock('@/components/recognition/recognition-wizard', () => ({
    RecognitionWizard: () => null,
}));
vi.mock('./_dialogs', () => ({
    BirthdaySettingsDialog: () => null,
    CreateHubPageDialog: () => null,
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

describe('Company Hub intranet layout', () => {
    beforeEach(() => {
        get.mockReset();
        visit.mockReset();
    });
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
        expect(banner.querySelector('.lucide-newspaper')).toBeInTheDocument();
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
    it('keeps the featured hero off Saved and explains how to save items', () => {
        render(<CompanyHub {...props} view="saved" />);
        expect(
            screen.getByRole('heading', { name: 'Saved for later' }),
        ).toBeVisible();
        expect(
            screen.queryByRole('region', { name: 'Featured on the hub' }),
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
    it('makes home feature tiles lead to real published content and resources', () => {
        render(<CompanyHub {...props} />);
        const hero = within(
            screen.getByRole('region', { name: 'Featured on the hub' }),
        );
        expect(
            hero.getByRole('link', { name: /A featured company story/ }),
        ).toHaveAttribute('href', '/company-hub/read/company-story');
        expect(
            hero.getByRole('link', { name: /Find your people/ }),
        ).toHaveAttribute('href', '/company-hub?view=people');
        expect(
            hero.getByRole('link', { name: /Your everyday essentials/ }),
        ).toHaveAttribute('href', '/company-hub?view=resources');
    });
    it('resets local update filters when a new main search opens', () => {
        const view = render(<CompanyHub {...props} view="updates" />);
        fireEvent.change(
            screen.getByRole('combobox', { name: 'Update type' }),
            { target: { value: 'events' } },
        );
        view.rerender(
            <CompanyHub {...props} view="updates" search="Handbook" />,
        );
        expect(
            screen.getByRole('combobox', { name: 'Update type' }),
        ).toHaveValue('all');
    });

    it('opens cross-Hub results for the main hub search', () => {
        render(<CompanyHub {...props} view="resources" />);
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Search Company Hub' }),
            { target: { value: 'Handbook' } },
        );
        fireEvent.submit(screen.getByRole('search'));
        expect(get).toHaveBeenCalledWith(
            '/company-hub',
            { search: 'Handbook', view: 'home' },
            { preserveState: true },
        );
        expect(
            screen.queryByRole('region', { name: 'Featured on the hub' }),
        ).not.toBeInTheDocument();
    });
});
