import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import type React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import MealPlannerOverviewPage from './meal-planner-overview';

// To-do #4: the side menu's Meal Planner opens on every site. The overview
// must never read as "0 conflicts" while it loads, and every row opens that
// site's own planner.

const mocks = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    get: vi.fn(),
    visit: vi.fn(),
    reload: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: React.ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: {
        get: mocks.get,
        visit: mocks.visit,
        reload: mocks.reload,
        on: () => () => {},
    },
    usePage: () => ({ props: mocks.props, url: '/catering' }),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => (
        <main>{children}</main>
    ),
}));

const week = {
    start: '2026-11-02',
    end: '2026-11-08',
    number: 45,
    is_current: true,
    previous: '2026-10-26',
    next: '2026-11-09',
    days: [
        '2026-11-02',
        '2026-11-03',
        '2026-11-04',
        '2026-11-05',
        '2026-11-06',
        '2026-11-07',
        '2026-11-08',
    ],
};

const days = (slots: number[]) =>
    week.days.map((date, i) => ({ date, meals: slots[i], slots: slots[i] }));

const site = (overrides: Record<string, unknown>) => ({
    id: 1,
    name: 'Aroha Respite',
    type: 'house',
    is_house: true,
    suburb: 'Ōtāhuhu',
    region: 'Auckland',
    residents: 3,
    week: {
        days: days([3, 3, 3, 3, 3, 3, 3]),
        meals: 21,
        served: 4,
        slots_filled: 21,
        slots_total: 21,
        fill_percent: 100,
    },
    allergens: { unresolved: 0, overrides: 0 },
    stock: { tracked: 4, low: 0, out: 0, at_par_percent: 100 },
    spend: { total_cents: 6240, budget_cents: 10000 },
    ...overrides,
});

const sites = [
    site({}),
    site({
        id: 2,
        name: 'Birch House',
        week: {
            days: days([2, 1, 0, 0, 0, 0, 0]),
            meals: 3,
            served: 0,
            slots_filled: 3,
            slots_total: 21,
            fill_percent: 14,
        },
        allergens: { unresolved: 2, overrides: 1 },
        stock: { tracked: 3, low: 1, out: 1, at_par_percent: 33 },
        spend: { total_cents: 12000, budget_cents: 10000 },
    }),
    site({
        id: 3,
        name: 'Head Office',
        type: 'head_office',
        is_house: false,
        residents: 0,
        week: {
            days: days([0, 0, 0, 0, 0, 0, 0]),
            meals: 0,
            served: 0,
            slots_filled: 0,
            slots_total: 21,
            fill_percent: 0,
        },
        stock: { tracked: 0, low: 0, out: 0, at_par_percent: null },
        spend: { total_cents: 0, budget_cents: null },
    }),
];

const table = () => screen.getByRole('table');

describe('Meal planner on all sites', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mocks.props = {
            auth: { user: { id: 7 } },
            week,
            can_create_sites: false,
        };
    });
    afterEach(cleanup);

    it('shows loading meters and rows, never a zero, while the sites load', () => {
        render(<MealPlannerOverviewPage />);

        expect(
            screen.getByRole('heading', { name: 'Meal planner' }),
        ).toBeTruthy();
        expect(screen.getAllByText('Loading…')).toHaveLength(4);
        expect(screen.getByLabelText('Loading sites')).toHaveAttribute(
            'aria-busy',
            'true',
        );
        expect(screen.queryByText('all clear')).toBeNull();
        expect(screen.queryByRole('table')).toBeNull();
    });

    it('lists every site with its week, conflicts, stock and spend', () => {
        mocks.props.overview = { week_start: week.start, sites };
        render(<MealPlannerOverviewPage />);

        const rows = within(table()).getAllByRole('row');
        expect(rows).toHaveLength(4); // header + 3 sites
        expect(within(rows[1]).getByText('Aroha Respite')).toBeTruthy();
        expect(within(rows[1]).getByText('All clear')).toBeTruthy();
        expect(within(rows[1]).getByText('$62.40 of $100.00')).toBeTruthy();
        expect(within(rows[2]).getByText('2 to resolve')).toBeTruthy();
        expect(within(rows[2]).getByText('1 override')).toBeTruthy();
        expect(within(rows[2]).getByText('1 out of stock')).toBeTruthy();
        expect(within(rows[2]).getByText('$120.00 · $20.00 over')).toBeTruthy();
        expect(within(rows[3]).getByText('Not tracked')).toBeTruthy();
        expect(within(rows[3]).getByText('No budget set')).toBeTruthy();

        // Each site's name opens its own planner.
        expect(
            screen.getByRole('link', {
                name: 'Open the meal planner for Birch House',
            }),
        ).toHaveAttribute('href', '/catering?site=2');

        // Header totals across the sites.
        expect(
            screen.getByText('at 1 house · review before serving'),
        ).toBeTruthy();
        expect(screen.getByText('1 site over budget')).toBeTruthy();
    });

    it('a meter shows the sites behind its number', () => {
        mocks.props.overview = { week_start: week.start, sites };
        render(<MealPlannerOverviewPage />);

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Show houses with allergen conflicts',
            }),
        );

        const rows = within(table()).getAllByRole('row');
        expect(rows).toHaveLength(2);
        expect(within(rows[1]).getByText('Birch House')).toBeTruthy();
    });

    it('search narrows the sites and says so when nothing matches', () => {
        mocks.props.overview = { week_start: week.start, sites };
        render(<MealPlannerOverviewPage />);

        fireEvent.change(
            screen.getByRole('searchbox', {
                name: 'Search sites, suburbs, regions…',
            }),
            { target: { value: 'zzz' } },
        );

        expect(screen.queryByRole('table')).toBeNull();
        expect(screen.getByText('No sites match')).toBeTruthy();
    });

    it('a row for another week opens the planner on that week', () => {
        mocks.props = {
            ...mocks.props,
            week: { ...week, start: '2026-11-09', is_current: false },
            overview: { week_start: '2026-11-09', sites },
        };
        render(<MealPlannerOverviewPage />);

        expect(
            screen.getByRole('link', {
                name: 'Open the meal planner for Aroha Respite',
            }),
        ).toHaveAttribute('href', '/catering?site=1&week=2026-11-09');
    });

    it('says plainly when there are no sites to plan for', () => {
        mocks.props.overview = { week_start: week.start, sites: [] };
        render(<MealPlannerOverviewPage />);

        expect(screen.getByText('No sites to plan meals for')).toBeTruthy();
        expect(screen.queryByRole('table')).toBeNull();
    });
});
