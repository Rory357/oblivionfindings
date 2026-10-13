import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { BlockContent } from './block-content';
import {
    catalog,
    defaultBlocks,
    fillRows,
    moveBlock,
    tidyBlocks,
    type OverviewProps,
    type Role,
} from './types';
import OverviewWorkspace from './workspace';

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        children,
        href,
        ...props
    }: React.AnchorHTMLAttributes<HTMLAnchorElement>) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: { on: vi.fn(() => () => {}), get: vi.fn(), reload: vi.fn() },
}));
vi.mock('sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const props = (): OverviewProps => ({
    role: 'worker',
    roles: ['worker'],
    sites: [{ id: 1, name: 'Permitted site' }],
    filters: { range: 'today', site: 'all' },
    preference: {
        revision: null,
        value: {
            blocks: null,
            copies: [],
            favorites: [],
            density: 'balanced',
            range: 'today',
        },
    },
    overview: {
        blocks: Object.fromEntries(
            catalog.worker.blocks.map((b) => [
                b.id,
                {
                    state: 'proposed',
                    subtitle: 'Proposed summary',
                    message: 'Not connected yet.',
                    rows: [],
                    series: [],
                    total: null,
                },
            ]),
        ),
        metrics: [],
        urgent: [],
        partial: false,
        scope: 'All permitted sites',
        period: { start: '2026-10-12', end: '2026-10-12' },
        updatedAt: '2026-10-12T00:00:00Z',
    },
});

beforeEach(() => {
    vi.stubGlobal(
        'ResizeObserver',
        class {
            observe() {}
            unobserve() {}
            disconnect() {}
        },
    );
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => ({
            ok: true,
            json: async () => ({ revision: 'saved-revision' }),
        })),
    );
});
afterEach(() => vi.unstubAllGlobals());

describe('overview arrangements', () => {
    it('shows independent progress measures without adding overlapping counts into a donut', () => {
        render(
            <BlockContent
                meta={
                    catalog.hr.blocks.find((block) => block.id === 'hr-survey')!
                }
                data={{
                    state: 'ready',
                    presentation: 'progress-rows',
                    subtitle: 'Participation',
                    rows: [
                        {
                            label: 'First survey',
                            value: 9,
                            target: 10,
                            href: '/hr/wellbeing/surveys/1',
                        },
                        { label: 'Second survey', value: 7, target: 20 },
                        { label: 'No audience', value: 0, target: 0 },
                    ],
                    total: 3,
                    series: [],
                }}
            />,
        );
        expect(
            screen.getByRole('progressbar', { name: 'First survey: 9 of 10' }),
        ).toHaveAttribute('max', '10');
        expect(
            screen.getByRole('progressbar', { name: 'Second survey: 7 of 20' }),
        ).toHaveAttribute('value', '7');
        expect(screen.getByText('No percentage to show')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /First survey/ }),
        ).toHaveAttribute('href', '/hr/wellbeing/surveys/1');
        expect(screen.queryByRole('img')).not.toBeInTheDocument();
    });

    it('gives an empty editable block a working manage-plans action', () => {
        const p = props();
        p.preference.value.blocks = [
            { id: 'worker-reminders', span: 6, height: 0, site: 'page' },
        ];
        p.overview.blocks['worker-reminders'] = {
            state: 'ready',
            subtitle: 'Recorded plans',
            total: 0,
            rows: [],
            series: [],
            href: '/work-plans/worker-reminders',
            actionLabel: 'Manage plans',
        };
        render(<OverviewWorkspace {...p} />);
        expect(
            screen.getByRole('link', { name: 'Manage plans' }),
        ).toHaveAttribute('href', '/work-plans/worker-reminders');
    });
    it('distinguishes restricted and unavailable library summaries from proposals', () => {
        const p = props();
        p.overview.blocks['worker-notes'].state = 'restricted';
        p.overview.blocks['worker-learning'].state = 'unavailable';
        render(<OverviewWorkspace {...p} />);
        fireEvent.click(screen.getByRole('button', { name: 'Edit layout' }));
        fireEvent.click(screen.getByRole('button', { name: 'Add blocks' }));
        expect(
            screen.getByRole('button', { name: 'Preview My unfinished notes' }),
        ).toHaveTextContent('Permission required');
        expect(
            screen.getByRole('button', { name: 'Preview My learning pathway' }),
        ).toHaveTextContent('Temporarily unavailable');
        expect(
            screen.getByRole('button', { name: 'Preview Latest updates' }),
        ).toHaveTextContent('Proposed summary');
    });

    it('labels hourly connection evidence and never paints missing checks as healthy', () => {
        const data = {
            state: 'ready' as const,
            subtitle: 'Recorded checks',
            total: 1,
            series: [],
            presentation: 'service-health' as const,
            rows: [
                {
                    label: 'Permitted site',
                    status: 'Needs attention',
                    href: '/security-devices',
                    history: [
                        {
                            at: '2026-10-12T00:00:00Z',
                            state: 'healthy' as const,
                            checks: 4,
                        },
                        {
                            at: '2026-10-12T01:00:00Z',
                            state: 'unknown' as const,
                            checks: 0,
                        },
                    ],
                },
            ],
        };
        const { container, rerender } = render(
            <BlockContent
                meta={catalog.it.blocks.find((b) => b.id === 'sites')!}
                data={data}
            />,
        );
        expect(
            screen.getByRole('img', {
                name: /Permitted site recorded connection checks/,
            }),
        ).toHaveAccessibleName(/No checks recorded/i);
        expect(
            container.querySelector('[data-state="unknown"]'),
        ).toHaveAttribute(
            'title',
            expect.stringMatching(/No checks recorded/i),
        );
        expect(screen.getByText('Unknown / no checks')).toBeInTheDocument();
        rerender(
            <BlockContent
                meta={catalog.it.blocks.find((b) => b.id === 'sites')!}
                data={data}
                preview
            />,
        );
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });
    it('keeps measured zero apart from future unknown in the chart figures, with one row of dates', () => {
        const series = Array.from({ length: 31 }, (_, index) => ({
            label: `2026-10-${String(index + 1).padStart(2, '0')}`,
            value: index < 12 ? 0 : null,
            comparison: index < 12 ? 0 : null,
        }));
        render(
            <BlockContent
                meta={catalog.it.blocks.find((b) => b.id === 'trend')!}
                data={{
                    state: 'ready',
                    subtitle: 'Support demand',
                    total: 0,
                    rows: [],
                    series,
                    seriesLabel: 'Opened',
                    comparisonLabel: 'Resolved',
                }}
            />,
        );
        // The chart itself is the date picker; no second row of date buttons.
        expect(
            screen.queryByRole('button', { name: /Mon 12/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText('Select a date on the chart to see its figures.'),
        ).toBeInTheDocument();
        const figures = screen.getByText(/View chart figures/).closest(
            'details',
        )!;
        expect(within(figures).getByText(/Mon 12 Oct/).parentElement).toHaveTextContent(
            /0 \/ 0/,
        );
        expect(within(figures).getByText(/Tue 13 Oct/).parentElement).toHaveTextContent(
            /— \/ —/,
        );
    });
    it('keeps team work destinations separate and library previews non-interactive', () => {
        const data = {
            state: 'ready' as const,
            subtitle: 'Team work',
            total: 1,
            series: [],
            presentation: 'connected-teams' as const,
            rows: [
                {
                    label: 'Willow Lodge',
                    detail: '3 on duty',
                    status: 'Cover needed',
                    href: '/operations/rostering',
                    handover: {
                        label: 'Submitted',
                        href: '/operations/handovers/8',
                    },
                    followups: { label: 'Permission required' },
                },
            ],
        };
        const meta = catalog.lead.blocks.find((block) => block.id === 'sites')!;
        const { rerender } = render(<BlockContent meta={meta} data={data} />);
        expect(
            screen.getByRole('link', { name: /Willow Lodge/ }),
        ).toHaveAttribute('href', '/operations/rostering');
        expect(screen.getByRole('link', { name: /Submitted/ })).toHaveAttribute(
            'href',
            '/operations/handovers/8',
        );
        expect(
            screen.queryByRole('link', { name: /Permission required/ }),
        ).not.toBeInTheDocument();
        expect(document.querySelector('a a')).toBeNull();
        rerender(<BlockContent meta={meta} data={data} preview />);
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });
    it('opens specific review requests even when the aggregate priority list is empty', () => {
        render(
            <BlockContent
                meta={catalog.manager.blocks[0]}
                expanded
                data={{
                    state: 'ready',
                    subtitle: 'Priorities',
                    total: 0,
                    rows: [],
                    series: [],
                    reviewTotal: 8,
                    reviewQueues: [
                        { label: 'Leave reviews', value: 8, href: '/hr/leave' },
                    ],
                    reviews: [
                        {
                            label: 'Leave request #12',
                            detail: 'Open request for details',
                            status: 'Awaiting review',
                            href: '/hr/leave/12',
                        },
                    ],
                }}
            />,
        );
        expect(
            screen.getByRole('region', { name: 'Decision queue' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /Leave request #12/ }),
        ).toHaveAttribute('href', '/hr/leave/12');
        expect(screen.queryByText('You’re up to date')).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /Leave reviews · 8/ }),
        ).toHaveAttribute('href', '/hr/leave');
        expect(
            screen.getByText(/Open a full workspace below/),
        ).toBeInTheDocument();
    });
    it('opens permitted decision papers without presenting a reading status', () => {
        render(
            <BlockContent
                meta={catalog.governance.blocks[0]}
                data={{
                    state: 'ready',
                    subtitle: 'Current decisions',
                    total: 1,
                    rows: [],
                    series: [],
                    presentation: 'decisions',
                    decisions: [
                        {
                            label: 'Quality policy review',
                            detail: 'Quality lead',
                            status: 'Open decision',
                            href: '/governance/resolutions/7',
                        },
                    ],
                }}
            />,
        );
        expect(
            screen.getByRole('link', { name: /Quality policy review/ }),
        ).toHaveAttribute('href', '/governance/resolutions/7');
        expect(screen.getByText('01')).toBeInTheDocument();
        expect(screen.queryByText('Read')).not.toBeInTheDocument();
    });
    it('keeps restricted assurance sources separate from visible risks', () => {
        render(
            <BlockContent
                meta={
                    catalog.governance.blocks.find(
                        (block) => block.id === 'trend',
                    )!
                }
                data={{
                    state: 'ready',
                    subtitle: 'Risk and assurance',
                    total: 1,
                    rows: [],
                    series: [],
                    presentation: 'assurance',
                    sections: [
                        {
                            title: 'Risks to watch',
                            caption: 'Current residual rating',
                            state: 'ready',
                            rows: [
                                {
                                    label: 'Continuity review',
                                    detail: 'Operations lead',
                                    status: 'High',
                                    tone: 'warning',
                                    href: '/governance/risks/4',
                                },
                            ],
                        },
                        {
                            title: 'Assurance evidence',
                            caption: 'Organisation-wide',
                            state: 'restricted',
                            rows: [],
                        },
                    ],
                }}
            />,
        );
        expect(
            screen.getByRole('link', { name: /Continuity review/ }),
        ).toHaveAttribute('href', '/governance/risks/4');
        expect(
            within(
                screen.getByRole('region', { name: 'Assurance evidence' }),
            ).getByText('Permission required'),
        ).toBeInTheDocument();
        expect(screen.queryByText('No items recorded')).not.toBeInTheDocument();
    });

    it('shows unknown strategic progress and keeps library previews non-interactive', () => {
        render(
            <BlockContent
                preview
                meta={
                    catalog.governance.blocks.find(
                        (block) => block.id === 'sites',
                    )!
                }
                data={{
                    state: 'ready',
                    subtitle: 'Current approved plans',
                    total: 1,
                    series: [],
                    presentation: 'priorities',
                    rows: [
                        {
                            label: 'Safer services',
                            value: null,
                            status: 'In progress',
                            href: '/governance/strategy/2',
                        },
                    ],
                }}
            />,
        );
        expect(
            screen.getByRole('img', {
                name: 'Safer services: Progress not recorded',
            }),
        ).toBeInTheDocument();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        expect(screen.queryByText('0%')).not.toBeInTheDocument();
    });

    it('does not show a training percentage when requirements are not configured', () => {
        render(
            <BlockContent
                meta={
                    catalog.hr.blocks.find(
                        (block) => block.id === 'distribution',
                    )!
                }
                data={{
                    state: 'ready',
                    subtitle: 'Staff training',
                    total: 52,
                    series: [],
                    measurementKnown: false,
                    rows: [
                        { label: 'Current', value: 0 },
                        { label: 'Not configured', value: 52 },
                    ],
                }}
            />,
        );
        expect(screen.getByText('Not assessed')).toBeInTheDocument();
        expect(screen.getByText('52')).toBeInTheDocument();
        expect(screen.queryByText('0%')).not.toBeInTheDocument();
    });
    it('places the HR pipeline beside training before workforce trends', () => {
        expect(defaultBlocks('hr').map((block) => block.id)).toEqual([
            'attention',
            'focus',
            'sites',
            'distribution',
            'trend',
            'agenda',
        ]);
    });
    it('renders coverage ratios and preserves unassessed windows instead of showing zero coverage', () => {
        render(
            <BlockContent
                meta={
                    catalog.lead.blocks.find((block) => block.id === 'trend')!
                }
                data={{
                    state: 'ready',
                    subtitle: 'Recorded staffing',
                    rows: [],
                    series: [],
                    total: 2,
                    coverage: {
                        date: '2026-10-12',
                        assigned: null,
                        required: null,
                        gaps: 1,
                        unassessed: 1,
                        windows: 2,
                        rows: [
                            {
                                id: 1,
                                name: 'Permitted site',
                                cells: [
                                    [
                                        {
                                            id: '1',
                                            starts: '2026-10-12T07:00:00+13:00',
                                            ends: '2026-10-12T15:00:00+13:00',
                                            assigned: 1,
                                            required: 2,
                                            gap: true,
                                            roleGap: false,
                                            href: '/operations/rostering?site_id[]=1',
                                        },
                                        {
                                            id: '2',
                                            starts: '2026-10-12T08:00:00+13:00',
                                            ends: '2026-10-12T12:00:00+13:00',
                                            assigned: null,
                                            required: 1,
                                            gap: false,
                                            roleGap: false,
                                            href: '/operations/rostering?site_id[]=1',
                                        },
                                    ],
                                    [],
                                    [],
                                ],
                            },
                        ],
                    },
                }}
            />,
        );
        expect(
            screen.getByRole('table', {
                name: 'Shift coverage by site and start time',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', {
                name: 'Permitted site, Morning: 1 of 2 assigned, staffing review needed',
            }),
        ).toHaveAttribute('href', '/operations/rostering?site_id[]=1');
        expect(screen.getByText('Not assessed')).toBeInTheDocument();
        expect(screen.getAllByText('No requirement')).toHaveLength(2);
        expect(screen.queryByText('Staffing matched')).not.toBeInTheDocument();
    });
    it('shows pipeline stages as counts with links to the recruitment board', () => {
        render(
            <BlockContent
                meta={catalog.hr.blocks.find((block) => block.id === 'sites')!}
                data={{
                    state: 'ready',
                    presentation: 'pipeline',
                    subtitle: 'Active candidates',
                    total: 7,
                    series: [],
                    rows: [
                        {
                            label: 'New',
                            value: 5,
                            href: '/hr/recruitment?tab=board',
                        },
                        {
                            label: 'Offer',
                            value: 2,
                            href: '/hr/recruitment?tab=board',
                        },
                    ],
                }}
            />,
        );
        expect(screen.getByText('active candidates')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /New/ })).toHaveAttribute(
            'href',
            '/hr/recruitment?tab=board',
        );
        expect(screen.getByText('5')).toBeInTheDocument();
        expect(screen.queryByText(/%/)).not.toBeInTheDocument();
    });
    it('shows formatted hours once as the main header value', () => {
        const p = props();
        p.overview.metrics = [
            {
                label: 'This week',
                value: 8,
                display: '8h',
                caption: '2 scheduled shifts · before breaks',
                href: '/my-calendar',
            },
        ];
        render(<OverviewWorkspace {...p} />);
        const meter = within(
            screen.getByRole('link', { name: 'View this week' }),
        );
        expect(meter.getByText('8h')).toBeInTheDocument();
        expect(meter.queryByText('8')).not.toBeInTheDocument();
    });
    it('renders assigned shift times in the Worker calendar without treating empty days as leave', () => {
        const meta = catalog.worker.blocks.find(
            (block) => block.id === 'trend',
        )!;
        render(
            <BlockContent
                meta={meta}
                data={{
                    state: 'ready',
                    subtitle: 'Next seven days',
                    rows: [],
                    series: [],
                    total: 0,
                    calendar: [
                        {
                            date: '2026-10-12',
                            count: 2,
                            hours: 10,
                            starts: '2026-10-12T07:00:00+13:00',
                            ends: '2026-10-12T15:00:00+13:00',
                        },
                        {
                            date: '2026-10-13',
                            count: 0,
                            hours: 0,
                            starts: null,
                            ends: null,
                        },
                    ],
                }}
            />,
        );
        expect(screen.getByText('7:00 am')).toBeInTheDocument();
        expect(screen.getByText('– 3:00 pm')).toBeInTheDocument();
        expect(screen.getByText('+ 1 more')).toBeInTheDocument();
        expect(screen.getByText('No shifts')).toBeInTheDocument();
        expect(screen.queryByText('Off')).not.toBeInTheDocument();
        expect(screen.getByText('hours')).toBeInTheDocument();
        expect(
            screen.getByText('2 scheduled shifts · before breaks'),
        ).toBeInTheDocument();
    });
    it('shows date-only deadlines without inventing a time', () => {
        render(
            <BlockContent
                meta={catalog.hr.blocks.find((block) => block.id === 'agenda')!}
                data={{
                    state: 'ready',
                    subtitle: 'People deadlines',
                    total: 1,
                    series: [],
                    rows: [{ label: 'Probation review', date: '2026-10-12' }],
                }}
            />,
        );
        expect(screen.getByText('Mon 12 Oct')).toBeInTheDocument();
        expect(screen.queryByText(/1:00 pm/)).not.toBeInTheDocument();
    });
    it('keeps an empty donut honest and labels a populated donut by its actual share', () => {
        const meta = catalog.manager.blocks.find(
            (block) => block.id === 'distribution',
        )!;
        const data = {
            state: 'ready' as const,
            subtitle: 'Current follow-ups',
            total: 0,
            rows: [],
            series: [],
        };
        const { rerender } = render(<BlockContent meta={meta} data={data} />);
        expect(
            screen.getByRole('img', { name: 'No records in this breakdown' }),
        ).toBeInTheDocument();
        expect(screen.getByText('—')).toBeInTheDocument();
        expect(screen.queryByText('0%')).not.toBeInTheDocument();
        rerender(
            <BlockContent
                meta={meta}
                data={{
                    ...data,
                    total: 20,
                    rows: [
                        { label: 'Closed', value: 15 },
                        { label: 'Open', value: 5 },
                    ],
                }}
            />,
        );
        expect(screen.getByText('75%')).toBeInTheDocument();
        expect(screen.getByText('20 in this breakdown')).toBeInTheDocument();
    });
    it('shows the first three priority categories and opens the full attention view', () => {
        const p = props();
        p.overview.blocks.attention = {
            state: 'ready',
            subtitle: 'Current priorities',
            total: 4,
            series: [],
            rows: ['First', 'Second', 'Third', 'Fourth'].map((label) => ({
                label,
                value: 1,
            })),
        };
        render(<OverviewWorkspace {...p} />);
        expect(screen.queryByText('Fourth')).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'View all 4 items' }),
        );
        expect(screen.getByText('Fourth')).toBeInTheDocument();
    });
    it('opens the full Manager decision queue from both primary actions', () => {
        const p = props();
        p.role = 'manager';
        p.roles = ['manager'];
        p.overview.primary = {
            label: 'Review decisions',
            href: '/dashboard',
            target: 'attention',
        };
        p.overview.blocks.focus = {
            state: 'ready',
            title: 'Your decision queue',
            subtitle: 'Prioritised reviews',
            href: '/incidents',
            actionTarget: 'attention',
            actionLabel: 'Review decision queue',
            headline: '4 reviews',
            total: 4,
            series: [],
            rows: [
                {
                    label: 'Operational work ready for review',
                    href: '/incidents',
                },
            ],
        };
        p.overview.blocks.attention = {
            state: 'ready',
            subtitle: 'Current priorities',
            total: 4,
            series: [],
            rows: ['First', 'Second', 'Third', 'Fourth'].map((label) => ({
                label,
                value: 1,
            })),
        };
        const { unmount } = render(<OverviewWorkspace {...p} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Review decision queue' }),
        );
        expect(screen.getByText('Fourth')).toBeInTheDocument();
        unmount();
        render(<OverviewWorkspace {...p} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Review decisions' }),
        );
        expect(screen.getByText('Fourth')).toBeInTheDocument();
    });
    it('keeps an empty decision queue action inside the overview', () => {
        const action = vi.fn();
        render(
            <BlockContent
                meta={catalog.manager.blocks.find((b) => b.id === 'focus')!}
                onAction={action}
                data={{
                    state: 'ready',
                    subtitle: 'Review queue',
                    href: '/incidents',
                    actionTarget: 'attention',
                    actionLabel: 'Review decision queue',
                    rows: [],
                    series: [],
                    total: 0,
                }}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Review decision queue' }),
        );
        expect(action).toHaveBeenCalledOnce();
        expect(
            screen.queryByRole('link', { name: 'Review decision queue' }),
        ).not.toBeInTheDocument();
    });
    it('distinguishes absent delivery data from a measured zero and gives site links', () => {
        render(
            <BlockContent
                meta={catalog.manager.blocks.find((b) => b.id === 'sites')!}
                data={{
                    state: 'ready',
                    subtitle: 'Delivery',
                    presentation: 'delivery',
                    total: 2,
                    series: [],
                    rows: [
                        {
                            label: 'No planned work',
                            value: 0,
                            target: 0,
                            detail: 'No ended shifts in this period',
                        },
                        {
                            label: 'Needs review',
                            value: 0,
                            target: 2,
                            detail: '2 shifts not recorded complete',
                            href: '/operations/shifts?site_id=1',
                            sparkline: [null, 0],
                        },
                    ],
                }}
            />,
        );
        expect(screen.getByText('—')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /Needs review/ }),
        ).toHaveAttribute('href', '/operations/shifts?site_id=1');
        expect(
            screen.getByText('2 shifts not recorded complete'),
        ).toBeInTheDocument();
    });
    it('keeps all six catalogues and starter sets complete and role-bound', () => {
        for (const role of Object.keys(catalog) as Role[]) {
            const entry = catalog[role],
                ids = entry.blocks.map((b) => b.id);
            expect(new Set(ids).size).toBe(20);
            expect(defaultBlocks(role)).toHaveLength(6);
            expect(entry.starters).toHaveLength(3);
            for (const starter of entry.starters) {
                expect(starter.ids).toHaveLength(6);
                expect(starter.ids.every((id) => ids.includes(id))).toBe(true);
            }
        }
    });
    it('fills complementary puzzle widths while preserving every block and reading order', () => {
        const blocks = [
            { id: 'a', span: 8, height: 600, site: '1' },
            { id: 'b', span: 8, height: 0, site: 'page' },
            { id: 'c', span: 4, height: 500, site: 'all' },
            { id: 'd', span: 4, height: 0, site: 'page' },
        ];
        expect(tidyBlocks(blocks).map((b) => b.id)).toEqual([
            'a',
            'c',
            'b',
            'd',
        ]);
        expect(tidyBlocks(blocks).map((b) => b.span)).toEqual([8, 4, 8, 4]);
        expect(tidyBlocks(blocks)[0]).toEqual({ ...blocks[0], height: 0 });
        expect(blocks[0].height).toBe(600);
        expect(moveBlock(blocks, 'a', -1)).toBe(blocks);
        expect(moveBlock(blocks, 'b', 1).map((b) => b.id)).toEqual([
            'a',
            'c',
            'b',
            'd',
        ]);
    });
    it('cancels removed blocks without writing preferences and keeps urgent work outside the layout', () => {
        const p = props();
        p.overview.urgent = [
            { label: 'Urgent item', value: 1, tone: 'critical' },
        ];
        render(<OverviewWorkspace {...p} />);
        fireEvent.click(screen.getByRole('button', { name: 'Edit layout' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove Needs attention' }),
        );
        expect(screen.getByText('Urgent item')).toBeInTheDocument();
        expect(
            screen.queryByRole('region', { name: 'Needs attention' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Undo layout change' }),
        );
        expect(
            screen.getByRole('region', { name: 'Needs attention' }),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(fetch).not.toHaveBeenCalled();
        expect(
            screen.getByRole('button', { name: 'Edit layout' }),
        ).toBeInTheDocument();
    });
    it('adds a searched optional block and saves its real layout, not the proposal data', async () => {
        render(<OverviewWorkspace {...props()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Edit layout' }));
        fireEvent.click(screen.getByRole('button', { name: 'Add blocks' }));
        const dialog = screen.getByRole('dialog');
        fireEvent.change(
            within(dialog).getByRole('textbox', { name: 'Search blocks' }),
            { target: { value: 'My leave requests' } },
        );
        fireEvent.click(
            within(dialog).getByRole('button', {
                name: 'Add block',
            }),
        );
        expect(
            within(dialog).getByRole('button', { name: 'Added' }),
        ).toBeDisabled();
        fireEvent.click(within(dialog).getByRole('button', { name: 'Close' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save layout' }));
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Edit layout' }),
            ).toBeInTheDocument(),
        );
        const call = vi.mocked(fetch).mock.calls[0];
        expect(call[0]).toBe('/dashboard/layout/worker');
        const saved = JSON.parse(call[1]!.body as string);
        expect(saved.value.blocks).toHaveLength(7);
        expect(saved.value.blocks[6]).toEqual({
            id: 'worker-leave',
            span: 6,
            height: 0,
            site: 'page',
        });
        expect(saved.value).not.toHaveProperty('data');
    });
    it('locks editing while a save is pending so newer edits cannot be discarded', () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => new Promise(() => {})),
        );
        render(<OverviewWorkspace {...props()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Edit layout' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save layout' }));
        expect(screen.getByRole('button', { name: 'Saving…' })).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Add blocks' }),
        ).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        expect(
            screen.getByRole('region', { name: 'Scrollable dashboard layout' }),
        ).toHaveAttribute('inert');
    });
    it('keeps a failed save editable so the user can recover', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => ({
                ok: false,
                json: async () => ({
                    message: 'Layout changed in another tab.',
                }),
            })),
        );
        render(<OverviewWorkspace {...props()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Edit layout' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save layout' }));
        await waitFor(() =>
            expect(screen.getByRole('alert')).toHaveTextContent(
                'Layout changed in another tab.',
            ),
        );
        expect(
            screen.getByRole('button', { name: 'Save layout' }),
        ).toBeEnabled();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeEnabled();
    });
});

describe('overview completion pass (13 Oct 2026)', () => {
    const attention = (rows: OverviewProps['overview']['urgent']) => ({
        state: 'ready' as const,
        subtitle: 'Current priorities',
        total: rows.length,
        series: [],
        rows,
    });

    it('calls only critical work urgent and leaves warnings in Needs attention', () => {
        const p = props();
        const warning = {
            label: 'Timesheets to finish',
            tone: 'warning' as const,
            href: '/timesheets',
        };
        p.overview.urgent = [warning];
        p.overview.blocks.attention = attention([warning]);
        const { unmount } = render(<OverviewWorkspace {...p} />);
        expect(screen.queryByText(/urgent item/)).not.toBeInTheDocument();
        unmount();
        const critical = {
            label: 'Staffing gap needs escalation',
            tone: 'critical' as const,
            href: '/rostering',
        };
        p.overview.urgent = [critical, warning];
        p.overview.blocks.attention = attention([critical, warning]);
        render(<OverviewWorkspace {...p} />);
        expect(screen.getByText('1 urgent item')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /Review now/ }));
        expect(screen.getByText('Also on your list')).toBeInTheDocument();
    });

    it('shows the next step and upcoming dates beside the full list in Needs attention', () => {
        const { container } = render(<OverviewWorkspace {...props()} />);
        fireEvent.click(screen.getByRole('tab', { name: /Needs attention/ }));
        expect(
            [...container.querySelectorAll('[data-block]')].map((el) =>
                el.getAttribute('data-block'),
            ),
        ).toEqual(['attention', 'focus', 'agenda']);
    });

    it('widens the last block in each row so a filtered view has no holes', () => {
        const blocks = [
            { id: 'trend', span: 8, height: 120, site: 'page' },
            { id: 'distribution', span: 4, height: 0, site: 'page' },
            { id: 'sites', span: 8, height: 0, site: 'page' },
            { id: 'a', span: 6, height: 0, site: 'page' },
            { id: 'b', span: 4, height: 0, site: 'page' },
        ];
        expect(fillRows(blocks).map((b) => b.span)).toEqual([8, 4, 12, 6, 6]);
        expect(fillRows(blocks).every((b) => b.height === 0)).toBe(true);
        // Display only: the saved layout is untouched.
        expect(blocks[2].span).toBe(8);
    });

    it('gives an empty focus card plain copy and a quiet route instead of a call to action', () => {
        render(
            <BlockContent
                meta={catalog.lead.blocks.find((b) => b.id === 'focus')!}
                data={{
                    state: 'ready',
                    subtitle: 'Next recorded handover',
                    href: '/operations/handovers',
                    headline: 'No handover due',
                    empty: 'No handover is recorded for your teams in the next 14 days.',
                    actionLabel: 'Review handover',
                    emptyActionLabel: 'Open handovers',
                    rows: [],
                    series: [],
                    total: 0,
                }}
            />,
        );
        expect(screen.getByText('No handover due')).toBeInTheDocument();
        const link = screen.getByRole('link', { name: /Open handovers/ });
        expect(link).toHaveAttribute('href', '/operations/handovers');
        // The outline (secondary) Button, not the primary call to action.
        expect(link.className).toContain('btn-soft-secondary');
        expect(
            screen.queryByRole('link', { name: /Review handover/ }),
        ).not.toBeInTheDocument();
    });

    it('opens read-only details for a row with facts and links on to its module', () => {
        const p = props();
        p.role = 'governance';
        p.roles = ['governance'];
        p.overview.blocks = Object.fromEntries(
            catalog.governance.blocks.map((b) => [
                b.id,
                {
                    state: 'ready',
                    subtitle: 'Live',
                    rows: [],
                    series: [],
                    total: 0,
                },
            ]),
        );
        p.overview.blocks.trend = {
            state: 'ready',
            subtitle: 'Risk and assurance',
            presentation: 'assurance',
            total: 1,
            rows: [],
            series: [],
            sections: [
                {
                    title: 'Risks to watch',
                    caption: 'Current residual rating',
                    state: 'ready',
                    rows: [
                        {
                            label: 'Service continuity',
                            detail: 'Operations lead',
                            status: 'High',
                            tone: 'warning',
                            href: '/governance/risks/7',
                            actionLabel: 'Open risk',
                            facts: [
                                { label: 'Owner', value: 'Operations lead' },
                                { label: 'Next review', date: '2026-10-20' },
                            ],
                        },
                    ],
                },
            ],
        };
        render(<OverviewWorkspace {...p} />);
        fireEvent.click(
            screen.getByRole('button', { name: /Service continuity/ }),
        );
        const dialog = screen.getByRole('dialog', {
            name: 'Service continuity',
        });
        expect(within(dialog).getByText('Owner')).toBeInTheDocument();
        expect(within(dialog).getByText(/20 Oct/)).toBeInTheDocument();
        expect(
            within(dialog).getByRole('link', { name: /Open risk/ }),
        ).toHaveAttribute('href', '/governance/risks/7');
    });

    it('sketches a block shape in the library instead of a bare zero', () => {
        render(<OverviewWorkspace {...props()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Edit layout' }));
        fireEvent.click(screen.getByRole('button', { name: /Add blocks/ }));
        const dialog = screen.getByRole('dialog', {
            name: 'Choose your blocks',
        });
        expect(dialog.querySelector('.ov-sketch')).not.toBeNull();
        expect(
            within(dialog).queryByText(/^0$/, { selector: 'strong' }),
        ).not.toBeInTheDocument();
    });
});
