import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { AnalyticsHeader, AnalyticsPeriodSummary } from './analytics-header';

vi.mock('@inertiajs/react', () => ({
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
}));
afterEach(cleanup);
const props = {
    filters: {
        from: '2026-07-01',
        to: '2026-07-14',
        site_id: 3,
        lens: 'governance',
        period: 'custom',
    },
    sites: [{ id: 3, name: 'Kauri House' }],
    scorecard: {
        leading: [
            {
                key: 'training_pct',
                label: 'Training',
                value: 85,
                suffix: '%',
                delta: 2,
                dir: 'improving',
            },
        ],
        lagging: [
            {
                key: 'incidents',
                label: 'Incidents',
                value: 2,
                suffix: '',
                delta: -1,
                dir: 'improving',
            },
            {
                key: 'ltifr',
                label: 'LTIFR',
                value: null,
                suffix: '',
                delta: null,
                dir: 'flat',
            },
        ],
    },
    period_summary: {
        incidents: 2,
        near_misses: 3,
        worksafe_awaiting: 1,
        open_hazards: 4,
        actions_on_time_pct: null,
        drills_complete: 1,
        drills_total: 4,
    },
    worksafe_notifiable: { notified: 0, awaiting: 1 },
    tab: 'overview',
    tabs: [
        { id: 'overview', label: 'Overview' },
        { id: 'trends', label: 'Trends' },
        { id: 'governance', label: 'Governance' },
    ],
    exportHref:
        '/health-safety/analytics/export?period=custom&site_id=3&view=incidents',
    reports: [
        {
            label: 'Board summary',
            href: '/health-safety/reports/board-summary?from=2026-07-01&to=2026-07-14&site_id=3',
        },
    ],
    canViewBoardReports: false,
};
function setup(overrides = {}) {
    const onReload = vi.fn(),
        onPeriod = vi.fn(),
        onTabChange = vi.fn();
    const view = render(
        <AnalyticsHeader
            {...props}
            {...overrides}
            onReload={onReload}
            onPeriod={onPeriod}
            onTabChange={onTabChange}
        />,
    );
    return { ...view, onReload, onPeriod, onTabChange };
}

describe('Analytics shared header', () => {
    it('keeps an unconfirmed measure free of a stale current delta', () => {
        setup({
            filters: { ...props.filters, lens: 'manager' },
            scorecard: {
                ...props.scorecard,
                leading: [
                    {
                        key: 'training_pct',
                        label: 'Training',
                        value: null,
                        suffix: '%',
                        delta: 5,
                        dir: 'watch',
                    },
                ],
            },
        });
        const training = within(
            screen.getByRole('button', { name: 'View training' }),
        );
        expect(training.getByText('Not confirmed')).toBeInTheDocument();
        expect(
            training.queryByText(/Increased by|Decreased by/),
        ).not.toBeInTheDocument();
    });

    it('keeps six interactive meters, scoped export and the connected view rail in the shared header', () => {
        const { onTabChange } = setup();
        const header = screen
            .getByRole('heading', { name: 'Health & Safety analytics' })
            .closest('header')!;
        expect(header).toHaveClass('eh-header');
        expect(header.querySelectorAll('.eh-meter')).toHaveLength(6);
        expect(screen.getByRole('link', { name: 'Export' })).toHaveAttribute(
            'href',
            props.exportHref,
        );
        expect(
            screen.getByRole('link', { name: 'Safety overview' }),
        ).toHaveAttribute('href', '/health-safety');
        fireEvent.click(screen.getByRole('button', { name: 'View incidents' }));
        expect(onTabChange).toHaveBeenCalledWith('breakdowns');
        fireEvent.click(screen.getByRole('button', { name: 'View ltifr' }));
        expect(onTabChange).toHaveBeenCalledWith('trends');
        expect(
            within(header).getByRole('tablist', { name: 'Analytics views' }),
        ).toBeInTheDocument();
    });

    it.each([null, 0, 45])(
        'retains the recorded LTI interval without claiming the full organisation is injury-free (%s)',
        (value) => {
            const { onTabChange } = setup({
                scorecard: {
                    ...props.scorecard,
                    lagging: [
                        ...props.scorecard.lagging,
                        {
                            key: 'days_since_lti',
                            label: 'Days LTI-free',
                            value,
                            suffix: '',
                            delta: null,
                            dir: 'flat',
                        },
                    ],
                },
            });
            const meter = screen.getByRole('button', {
                name: 'View days since lti',
            });
            expect(
                within(meter).getByText('Days since LTI'),
            ).toBeInTheDocument();
            expect(
                within(meter).getByText(value === null ? '—' : String(value)),
            ).toBeInTheDocument();
            expect(
                within(meter).getByText(
                    value === null
                        ? 'Not confirmed'
                        : 'Last readable lost-time injury',
                ),
            ).toBeInTheDocument();
            expect(screen.queryByText('Days LTI-free')).not.toBeInTheDocument();
            fireEvent.click(meter);
            expect(onTabChange).toHaveBeenCalledWith('overview');
        },
    );

    it('keeps unconfirmed rates and incomplete drills truthful', () => {
        setup();
        expect(
            within(
                screen.getByRole('button', { name: 'View ltifr' }),
            ).getByText('—'),
        ).toBeInTheDocument();
        expect(
            within(
                screen.getByRole('button', { name: 'View ltifr' }),
            ).getByText('Not confirmed'),
        ).toBeInTheDocument();
        expect(screen.getByText('1/4')).toBeInTheDocument();
        expect(
            screen.getByText('Recorded completed drills'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(/overdue|all clear|all compliant/i),
        ).not.toBeInTheDocument();
        expect(screen.getByText('Decreased by')).toBeInTheDocument();
    });

    it('switches lens and Site through the existing filter callbacks without silently clearing a withdrawn Site', () => {
        const { onReload, rerender, onPeriod, onTabChange } = setup();
        fireEvent.click(screen.getByRole('button', { name: 'Governance' }));
        fireEvent.click(screen.getByRole('button', { name: 'Manager' }));
        expect(onReload).toHaveBeenCalledWith({ lens: 'manager' });
        rerender(
            <AnalyticsHeader
                {...props}
                filters={{ ...props.filters, site_id: 999, lens: 'manager' }}
                onReload={onReload}
                onPeriod={onPeriod}
                onTabChange={onTabChange}
            />,
        );
        expect(screen.getByText(/Site access unconfirmed/)).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'View training' }),
        ).toHaveTextContent('85%');
        expect(onReload).toHaveBeenCalledTimes(1);
    });

    it('preserves period presets and refuses a partial calendar range before applying exact date-only endpoints', () => {
        const { onPeriod, onReload } = setup();
        fireEvent.click(screen.getByRole('button', { name: 'Custom range' }));
        fireEvent.click(screen.getByRole('button', { name: 'Last 30 days' }));
        expect(onPeriod).toHaveBeenCalledWith('30d');
        fireEvent.click(screen.getByRole('button', { name: 'Set dates' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Wed 8 July 2026' }),
        );
        expect(
            screen.getByRole('button', { name: 'Apply range' }),
        ).toBeDisabled();
        expect(onReload).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Fri 17 July 2026' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Apply range' }));
        expect(onReload).toHaveBeenCalledExactlyOnceWith({
            period: 'custom',
            from: '2026-07-08',
            to: '2026-07-17',
        });
    });

    it('cancels dates and refreshes the calendar month when authoritative filters change', () => {
        const { rerender, onReload, onPeriod, onTabChange } = setup();
        fireEvent.click(screen.getByRole('button', { name: 'Set dates' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Wed 8 July 2026' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Cancel', exact: true }),
        );
        expect(onReload).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Set dates' }));
        expect(
            within(
                screen.getByRole('group', { name: 'Analytics period' }),
            ).getByRole('status'),
        ).toHaveTextContent('1 Jul 2026 – 14 Jul 2026 · inclusive');
        rerender(
            <AnalyticsHeader
                {...props}
                filters={{
                    ...props.filters,
                    from: '2026-10-01',
                    to: '2026-10-12',
                }}
                onReload={onReload}
                onPeriod={onPeriod}
                onTabChange={onTabChange}
            />,
        );
        expect(screen.getByText('October 2026')).toBeInTheDocument();
        expect(
            within(
                screen.getByRole('group', { name: 'Analytics period' }),
            ).getByRole('status'),
        ).toHaveTextContent('1 Oct 2026 – 12 Oct 2026 · inclusive');
    });

    it('only offers board exports with the supplied capability and retains dated safe links', () => {
        const { rerender, onReload, onPeriod, onTabChange } = setup();
        expect(
            screen.queryByRole('button', { name: 'Board reports' }),
        ).not.toBeInTheDocument();
        rerender(
            <AnalyticsHeader
                {...props}
                canViewBoardReports
                onReload={onReload}
                onPeriod={onPeriod}
                onTabChange={onTabChange}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Board reports' }));
        const report = screen.getByRole('link', { name: 'Board summary' });
        expect(report).toHaveAttribute('href', props.reports[0].href);
        expect(report).toHaveAttribute('target', '_blank');
        expect(report).toHaveAttribute('rel', 'noopener noreferrer');
    });

    it('preserves the expandable recorded activity without a fabricated action percentage', () => {
        render(
            <AnalyticsPeriodSummary
                summary={props.period_summary}
                sdsExpiring={2}
            />,
        );
        expect(
            screen.getByText('Actions on time unconfirmed'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('2 expiring safety data sheets'),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Hide activity' }));
        expect(
            screen.queryByText('2 expiring safety data sheets'),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Show activity' }));
        expect(
            screen.getByText('2 expiring safety data sheets'),
        ).toBeInTheDocument();
    });
});
