import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import type { AnchorHTMLAttributes, ComponentProps } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    ParticipationHeader,
    ParticipationTally,
    type ParticipationHero,
} from './participation-header';
import { router } from '@inertiajs/react';
import { SafetyWorkspaceRail } from '@/components/health-safety/workspace-header';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        preserveState: _state,
        preserveScroll: _scroll,
        ...props
    }: AnchorHTMLAttributes<HTMLAnchorElement> & {
        preserveState?: boolean;
        preserveScroll?: boolean;
    }) => <a {...props} />,
    router: { get: vi.fn(), visit: vi.fn() },
    usePage: () => ({ props: { auth: { can: { hazards: { view: true } } } } }),
}));
afterEach(cleanup);
beforeEach(() => vi.clearAllMocks());

const hero: ParticipationHero = {
    clusters: {
        participation: {
            active_reps: 7,
            sites_without_rep: 1,
            committees: 2,
            meetings_quarter: 3,
        },
        consultation: {
            open: 4,
            awaiting_feedback: 6,
            overdue_actions: 5,
            meetings_with_outstanding_actions: 2,
            minutes_outstanding: 2,
        },
    },
    badges: {
        reps_coverage_pct: 75,
        sites_total: 4,
        sites_covered: 3,
        minutes_overdue: 2,
        consultations_awaiting: 6,
        training_below_minimum: 1,
    },
};
const filters = {
    tab: 'representatives',
    site_id: 3,
    status: null,
    period: 'quarter',
    q: 'applied',
};
const props: ComponentProps<typeof ParticipationHeader> = {
    filters,
    hero,
    sites: [
        { id: 3, name: 'House Three' },
        { id: 4, name: 'House Four' },
    ],
    periods: [
        { key: 'quarter', label: 'Quarter' },
        { key: 'week', label: 'This week' },
    ],
    statuses: [
        ['active', 'Active'],
        ['resigned', 'Resigned'],
    ] as [string, string][],
    onFilters: vi.fn(),
    onClear: vi.fn(),
};

describe('Participation shared header', () => {
    it('shows and clears an applied focus and drops stale focus from unrelated summary links', () => {
        render(
            <ParticipationHeader
                {...props}
                filters={{
                    ...filters,
                    period: 'all',
                    q: null,
                    focus: 'training_below_two',
                }}
            />,
        );
        expect(
            screen.getByRole('button', {
                name: 'Active representatives with under 2 training days',
            }),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Clear Show' }));
        expect(props.onFilters).toHaveBeenCalledExactlyOnceWith({
            focus: null,
            status: null,
            period: 'all',
        });
        const target = new URL(
            screen
                .getByRole('link', { name: 'View open consultations' })
                .getAttribute('href')!,
            'https://example.test',
        );
        expect(target.searchParams.has('focus')).toBe(false);
        expect(target.searchParams.get('site_id')).toBe('3');
    });
    it('shows the People and participation hub rail, whose three views are the page tabs', () => {
        render(
            <ParticipationHeader
                {...props}
                filters={{ ...filters, tab: 'meetings' }}
                rail={<SafetyWorkspaceRail hub="participation" current="meetings" />}
            />,
        );
        const rail = screen.getByRole('tablist', {
            name: 'People and participation views',
        });
        expect(
            within(rail).getByRole('tab', { name: /Committees/ }),
        ).toHaveAttribute('aria-selected', 'true');
        expect(
            screen.queryByRole('tablist', { name: 'Participation views' }),
        ).not.toBeInTheDocument();
        fireEvent.click(within(rail).getByRole('tab', { name: /Consultations/ }));
        expect(router.visit).toHaveBeenCalledExactlyOnceWith(
            '/health-safety/worker-participation?tab=consultations',
        );
    });
    it('retains the recorded counts and linked views without claiming all work is compliant', () => {
        // Four number boxes in the header; the other two lead the body as a tally.
        render(
            <>
                <ParticipationHeader {...props} />
                <ParticipationTally filters={filters} hero={hero} meetingCount={12} />
            </>,
        );
        expect(
            screen.getByRole('heading', { name: 'Worker participation' }),
        ).toBeInTheDocument();
        const expected = [
            ['Active representatives', '7', 'representatives'],
            ['All meetings', '12', 'meetings'],
            ['Open consultations', '4', 'consultations'],
            ['Meetings with unfinished actions', '2', 'meetings'],
            ['Minutes missing', '2', 'meetings'],
            ['Representatives with under 2 training days', '1', 'representatives'],
        ];
        for (const [label, value, tab] of expected) {
            const link = screen.getByRole('link', {
                name: `View ${label.toLowerCase()}`,
            });
            expect(within(link).getByText(value)).toBeInTheDocument();
            const href = new URL(
                link.getAttribute('href')!,
                'https://example.test',
            );
            expect(href.pathname).toBe('/health-safety/worker-participation');
            expect(href.searchParams.get('site_id')).toBe('3');
            expect(href.searchParams.get('tab')).toBe(tab);
            expect(href.searchParams.has('q')).toBe(false);
            expect(link.className).toMatch(
                ['All meetings', 'Representatives with under 2 training days'].includes(label)
                    ? /sg-t--link/
                    : /eh-number/,
            );
        }
        expect(
            screen.getByText(
                '3 of 4 sites have a representative · 1 without one',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText('2 active committees · 3 meetings this quarter'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(
                /all filed|up to date|minimum met|coverage gap/i,
            ),
        ).not.toBeInTheDocument();
    });

    it('links populations to matching all-period views and retains the action sum as context', () => {
        render(
            <>
                <ParticipationHeader {...props} />
                <ParticipationTally filters={filters} hero={hero} meetingCount={12} />
            </>,
        );
        for (const label of [
            'active representatives',
            'open consultations',
            'meetings with unfinished actions',
            'all meetings',
            'minutes missing',
            'representatives with under 2 training days',
        ]) {
            const href = new URL(
                screen
                    .getByRole('link', { name: `View ${label}` })
                    .getAttribute('href')!,
                'https://example.test',
            );
            expect(href.searchParams.get('period')).toBe('all');
        }
        const meetings = new URL(
            screen
                .getByRole('link', { name: 'View all meetings' })
                .getAttribute('href')!,
            'https://example.test',
        );
        expect(meetings.searchParams.get('period')).toBe('all');
        expect(meetings.searchParams.has('focus')).toBe(false);
        expect(
            screen.getByText('5 actions still to do from these meetings'),
        ).toBeInTheDocument();
        for (const [label, focus] of [
            ['minutes missing', 'minutes_missing'],
            ['representatives with under 2 training days', 'training_below_two'],
            ['meetings with unfinished actions', 'actions_outstanding'],
        ]) {
            const target = new URL(
                screen
                    .getByRole('link', { name: `View ${label}` })
                    .getAttribute('href')!,
                'https://example.test',
            );
            expect(target.searchParams.get('focus')).toBe(focus);
            expect(target.searchParams.get('site_id')).toBe('3');
        }
    });

    it('submits search on Enter, adopts refreshed applied search and clears unsent text', () => {
        const view = render(<ParticipationHeader {...props} />);
        const search = screen.getByRole('searchbox', {
            name: 'Search participation records',
        });
        fireEvent.change(search, { target: { value: 'new search' } });
        expect(props.onFilters).not.toHaveBeenCalled();
        fireEvent.keyDown(search, { key: 'Enter' });
        expect(props.onFilters).toHaveBeenCalledExactlyOnceWith({
            q: 'new search',
        });
        view.rerender(
            <ParticipationHeader
                {...props}
                filters={{ ...filters, q: 'server refresh' }}
            />,
        );
        expect(search).toHaveValue('server refresh');
        fireEvent.change(search, { target: { value: 'unsent' } });
        fireEvent.click(screen.getByRole('button', { name: 'Clear filters' }));
        expect(search).toHaveValue('');
        expect(props.onClear).toHaveBeenCalledOnce();
    });

    it('keeps Site, period and status interactive through the real shared controls', () => {
        render(<ParticipationHeader {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Clear Site' }));
        expect(props.onFilters).toHaveBeenLastCalledWith({ site_id: null });
        fireEvent.click(
            screen.getByRole('button', { name: 'Quarter' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'All periods' }),
        );
        expect(props.onFilters).toHaveBeenLastCalledWith({ period: 'all' });
        fireEvent.click(
            screen.getByRole('button', { name: 'Status' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Resigned' }),
        );
        expect(props.onFilters).toHaveBeenLastCalledWith({
            status: 'resigned',
        });
    });

    it('shows unavailable coverage when no Site denominator exists and retains readable zero values', () => {
        render(
            <ParticipationHeader
                {...props}
                hero={{
                    clusters: {
                        participation: {
                            active_reps: 0,
                            sites_without_rep: 0,
                            committees: 0,
                            meetings_quarter: 0,
                        },
                        consultation: {
                            open: 0,
                            awaiting_feedback: 0,
                            overdue_actions: 0,
                            meetings_with_outstanding_actions: 0,
                            minutes_outstanding: 0,
                        },
                    },
                    badges: {
                        reps_coverage_pct: 0,
                        sites_total: 0,
                        sites_covered: 0,
                        minutes_overdue: 0,
                        consultations_awaiting: 0,
                        training_below_minimum: 0,
                    },
                }}
            />,
        );
        expect(
            screen.getByText('No sites to check yet'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(/0 of 0 sites/),
        ).not.toBeInTheDocument();
        for (const link of screen.getAllByRole('link'))
            expect(link).not.toHaveClass('eh-meter--success');
        expect(
            screen.getByRole('link', { name: 'View active representatives' }),
        ).toHaveTextContent('0');
    });
});
