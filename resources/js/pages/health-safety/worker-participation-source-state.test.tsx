import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
import { cloneElement, type ReactElement } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ScorecardCell } from './analytics';
import { ChartCard, WorkerParticipationChart } from './analytics-charts';
import type { TrendPoint } from './analytics-types';
import {
    participationViewer,
    workerParticipationTable,
} from './participation-source-state';
const audience = vi.hoisted(() => ({ id: 7, allowed: true }));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            auth: {
                user: { id: audience.id },
                can: { hazards: { view: audience.allowed } },
            },
        },
    }),
}));

// Supply fixed geometry only; actual Recharts lines, axes, chart and accessible table remain mounted.
vi.mock('recharts', async (original) => ({
    ...(await original<typeof import('recharts')>()),
    ResponsiveContainer: ({
        children,
    }: {
        children: ReactElement<{ width?: number; height?: number }>;
    }) => cloneElement(children, { width: 600, height: 220 }),
}));
afterEach(() => {
    cleanup();
    audience.id = 7;
    audience.allowed = true;
});
function point(
    access: TrendPoint['worker_participation_source_access'],
    engagement: number | null = 97,
    consultation: number | null = 61,
): TrendPoint {
    return {
        month: '2026-10',
        label: 'Oct',
        worker_engagement: engagement,
        worker_consultation: consultation,
        worker_participation_source_access: access,
        worker_participation_viewer_id: 7,
    } as TrendPoint;
}
function ParticipationDisplay({ trends }: { trends: TrendPoint[] }) {
    return (
        <ChartCard
            title="Worker participation"
            aria="Worker participation trend"
            table={{
                caption: 'Participation by month',
                columns: ['Month', 'Meetings completed %', 'Consultation %'],
                rows: workerParticipationTable(
                    trends,
                    participationViewer({
                        user: { id: audience.id },
                        can: { hazards: { view: audience.allowed } },
                    }),
                ),
            }}
        >
            <WorkerParticipationChart trends={trends} />
        </ChartCard>
    );
}
function show(trends: TrendPoint[]) {
    return render(<ParticipationDisplay trends={trends} />);
}
describe('Participation chart and accessible source state', () => {
    it('uses the current access denial even when no retained month exists', () => {
        audience.allowed = false;
        const view = show([]);
        expect(
            screen.getByText('Worker-participation access is restricted.'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(
                'Worker-participation information is not confirmed.',
            ),
        ).not.toBeInTheDocument();
        expect(
            view.container.querySelector('.recharts-surface'),
        ).not.toBeInTheDocument();
    });
    it("does not label the current viewer restricted from another actor's retained chart or scorecard metadata", () => {
        const trends = [
            { ...point('restricted'), worker_participation_viewer_id: 8 },
        ];
        const view = show(trends);
        expect(
            screen.getByText(
                'Worker-participation information is not confirmed.',
            ),
        ).toBeInTheDocument();
        expect(screen.getByRole('table')).not.toHaveTextContent(
            'Access restricted',
        );
        expect(screen.getByRole('table')).not.toHaveTextContent('97');
        view.unmount();
        render(
            <ScorecardCell
                item={{
                    key: 'worker_consultation',
                    label: 'Consultation completion',
                    value: 97,
                    delta: 12,
                    dir: 'improving',
                    suffix: '%',
                    source_access: 'restricted',
                    viewer_id: 8,
                }}
            />,
        );
        expect(screen.getByText('Not confirmed')).toBeInTheDocument();
        expect(screen.queryByText('Access restricted')).not.toBeInTheDocument();
        expect(screen.queryByText('97')).not.toBeInTheDocument();
    });
    it('masks both retained value and trend in the actual restricted participation scorecard cell', () => {
        render(
            <ScorecardCell
                item={{
                    key: 'worker_engagement',
                    label: 'Worker participation',
                    value: 97,
                    delta: 12,
                    dir: 'improving',
                    suffix: '%',
                    source_access: 'restricted',
                    viewer_id: 7,
                }}
            />,
        );
        expect(screen.getByText('Access restricted')).toBeInTheDocument();
        expect(screen.queryByText('97')).not.toBeInTheDocument();
        expect(screen.queryByText(/vs last mo/)).not.toBeInTheDocument();
    });
    it('preserves the recorded value and trend in the same readable scorecard cell', () => {
        render(
            <ScorecardCell
                item={{
                    key: 'worker_consultation',
                    label: 'Consultation completion',
                    value: 83,
                    delta: 12,
                    dir: 'improving',
                    suffix: '%',
                    source_access: 'readable',
                    viewer_id: 7,
                }}
            />,
        );
        expect(screen.getByText(/\+12 vs last mo/)).toBeInTheDocument();
        expect(screen.getByText('83')).toBeInTheDocument();
        expect(screen.queryByText('Access restricted')).not.toBeInTheDocument();
    });
    it.each(['restricted', 'unavailable', undefined] as const)(
        'withholds retained private percentages from the visual and accessible table when source is %s',
        (access) => {
            const view = show([point(access)]);
            expect(
                screen.getByText(
                    access === 'restricted'
                        ? 'Worker-participation access is restricted.'
                        : 'Worker-participation information is not confirmed.',
                ),
            ).toBeInTheDocument();
            expect(
                view.container.querySelector('.recharts-surface'),
            ).not.toBeInTheDocument();
            const table = screen.getByRole('table');
            expect(table).not.toHaveTextContent('97');
            expect(table).not.toHaveTextContent('61');
            expect(
                within(table).getAllByText(
                    access === 'restricted'
                        ? 'Access restricted'
                        : 'Not confirmed',
                ),
            ).toHaveLength(2);
        },
    );
    it('distinguishes a readable empty population from restricted or unconfirmed information', () => {
        show([point('readable', null, null)]);
        expect(
            screen.getByText(
                'No readable worker-participation records for this period.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(
                /information is not confirmed|access is restricted/,
            ),
        ).not.toBeInTheDocument();
        expect(
            within(screen.getByRole('table')).getAllByText('—'),
        ).toHaveLength(2);
    });
    it('keeps readable zero percentages in the real plot and accessible table', async () => {
        const view = show([point('readable', 0, 0)]);
        expect(
            view.container.querySelector('.recharts-surface'),
        ).toBeInTheDocument();
        // Recharts 3 draws axis ticks after its first layout pass.
        await waitFor(() =>
            expect(
                view.container.querySelector('.recharts-yAxis'),
            ).toHaveTextContent('0'),
        );
        expect(
            within(screen.getByRole('table')).getAllByText('0'),
        ).toHaveLength(2);
        expect(
            screen.queryByText(/No readable|not confirmed/),
        ).not.toBeInTheDocument();
    });
    it('retains real positive percentages in the same plot and table', () => {
        const view = show([point('readable', 83, 72)]);
        expect(
            view.container.querySelector('.recharts-surface'),
        ).toBeInTheDocument();
        expect(
            within(screen.getByRole('table')).getByText('83'),
        ).toBeInTheDocument();
        expect(
            within(screen.getByRole('table')).getByText('72'),
        ).toBeInTheDocument();
    });
    it('does not expose a stale neighbouring month alongside readable evidence', () => {
        const view = show([
            { ...point('restricted'), month: '2026-09', label: 'Sep' },
            point('readable', 83, 72),
        ]);
        const table = screen.getByRole('table');
        expect(table).not.toHaveTextContent('97');
        expect(table).not.toHaveTextContent('61');
        expect(table).toHaveTextContent('83');
        expect(
            view.container.querySelectorAll('.recharts-line-dot'),
        ).toHaveLength(2);
    });
    it.each(['account', 'permission'] as const)(
        'removes retained chart and accessible percentages on a shared %s change',
        (change) => {
            const trends = [point('readable', 83, 72)];
            const view = show(trends);
            expect(screen.getByRole('table')).toHaveTextContent('83');
            if (change === 'account') audience.id = 8;
            else audience.allowed = false;
            view.rerender(<ParticipationDisplay trends={trends} />);
            expect(
                view.container.querySelector('.recharts-surface'),
            ).not.toBeInTheDocument();
            const table = screen.getByRole('table');
            expect(table).not.toHaveTextContent('83');
            expect(table).not.toHaveTextContent('72');
            expect(
                within(table).getAllByText(
                    change === 'permission'
                        ? 'Access restricted'
                        : 'Not confirmed',
                ),
            ).toHaveLength(2);
        },
    );
    it.each(['account', 'permission'] as const)(
        'removes both retained scorecard value and prior-month delta on a shared %s change',
        (change) => {
            const cell = (
                <ScorecardCell
                    item={{
                        key: 'worker_consultation',
                        label: 'Consultation completion',
                        value: 83,
                        delta: 12,
                        dir: 'improving',
                        suffix: '%',
                        source_access: 'readable',
                        viewer_id: 7,
                    }}
                />
            );
            const view = render(cell);
            expect(screen.getByText('83')).toBeInTheDocument();
            expect(screen.getByText(/\+12 vs last mo/)).toBeInTheDocument();
            if (change === 'account') audience.id = 8;
            else audience.allowed = false;
            view.rerender(
                <ScorecardCell
                    item={{
                        key: 'worker_consultation',
                        label: 'Consultation completion',
                        value: 83,
                        delta: 12,
                        dir: 'improving',
                        suffix: '%',
                        source_access: 'readable',
                        viewer_id: 7,
                    }}
                />,
            );
            expect(screen.queryByText('83')).not.toBeInTheDocument();
            expect(screen.queryByText(/vs last mo/)).not.toBeInTheDocument();
            expect(
                screen.getByText(
                    change === 'permission'
                        ? 'Access restricted'
                        : 'Not confirmed',
                ),
            ).toBeInTheDocument();
        },
    );
});
