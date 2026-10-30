import type { HubContent, HubPerson, HubProps } from './_types';

export const hubContent: HubContent = {
    title: 'Our initiative',
    summary: 'A shared focus',
    category: 'Learning',
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
            id: 'intro',
            columns: 1,
            blocks: [
                {
                    id: 'body',
                    type: 'text',
                    title: 'About this initiative',
                    body: 'Making space to learn.',
                },
            ],
        },
    ],
};
export const hubPerson: HubPerson = {
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
export const hubStory = {
    id: 50,
    slug: 'learning-week',
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
export function hubData(patch: Partial<HubProps> = {}): HubProps {
    return {
        home: hubContent,
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
        ...patch,
    };
}
