import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import axios from 'axios';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ItReports } from '@/components/it/it-reports';

vi.mock('axios');

vi.mock('recharts', () => ({
    Area: () => null,
    AreaChart: ({ children }: { children: React.ReactNode }) => (
        <div>{children}</div>
    ),
    CartesianGrid: () => null,
    Cell: () => null,
    Pie: ({ children }: { children?: React.ReactNode }) => (
        <div>{children}</div>
    ),
    PieChart: ({ children }: { children: React.ReactNode }) => (
        <div>{children}</div>
    ),
    ResponsiveContainer: ({ children }: { children: React.ReactNode }) => (
        <div>{children}</div>
    ),
    Tooltip: () => null,
    XAxis: () => null,
    YAxis: () => null,
}));

function deferred<T>() {
    let resolve!: (value: T) => void;
    let reject!: (reason?: unknown) => void;
    const promise = new Promise<T>((resolvePromise, rejectPromise) => {
        resolve = resolvePromise;
        reject = rejectPromise;
    });

    return { promise, resolve, reject };
}

function reportData({
    from,
    to,
    days,
    open,
}: {
    from: string;
    to: string;
    days: number;
    open: number;
}) {
    return {
        range: { from, to, days },
        kpis: {
            open,
            unassigned: 0,
            breaching: 0,
            breached: 0,
            resolved: 0,
            avg_first_response_mins: null,
            avg_resolution_mins: null,
            sla_compliance: null,
            sla_met: 0,
            sla_measured: 0,
            csat_avg: null,
            csat_response_rate: null,
        },
        trend: [{ date: to, created: open, resolved: 0 }],
        by_priority: [],
        by_category: [],
        top_requesters: [],
        agent_workload: [],
        provisioning: { raised: 0, fulfilled: 0, avg_days: null },
        backlog_age: {},
        reopen_rate: { resolved: 0, rate: null, href: '/it?tab=tickets' },
        first_contact_resolution: {
            resolved: 0,
            rate: null,
            href: '/it?tab=tickets',
        },
        channels: {},
        major_incidents: { declared: 0, restored: 0, open: 0 },
        change_success: {
            successful: 0,
            failed: 0,
            inconclusive: 0,
        },
        recurring_problems: { total: 0, known_errors: 0, root_causes: 0 },
        automation_outcomes: { succeeded: 0, failed: 0, skipped: 0 },
        service_reliability: [],
        device_reliability: {
            affected_devices: 0,
            open_incidents: 0,
            recovered: 0,
        },
        quality: {},
    };
}

type ReportData = ReturnType<typeof reportData>;

const outcomeOnlyCases: Array<[string, (data: ReportData) => void]> = [
    [
        'successful changes',
        (data) => {
            data.change_success.successful = 1;
        },
    ],
    [
        'recurring problems',
        (data) => {
            data.recurring_problems.total = 1;
        },
    ],
    [
        'skipped automation',
        (data) => {
            data.automation_outcomes.skipped = 1;
        },
    ],
    [
        'affected Devices',
        (data) => {
            data.device_reliability.affected_devices = 1;
        },
    ],
];

beforeEach(() => {
    vi.clearAllMocks();
});

describe('IT reports request truthfulness', () => {
    it('shows permission-restricted outcomes without inventing zero counts or destinations', async () => {
        const data = {
            ...reportData({
                from: '2026-06-01',
                to: '2026-06-07',
                days: 7,
                open: 0,
            }),
            automation_outcomes: {
                access: 'restricted' as const,
                succeeded: null,
                failed: null,
                skipped: null,
                href: null,
            },
            device_reliability: {
                access: 'restricted' as const,
                affected_devices: null,
                open_incidents: null,
                recovered: null,
                href: null,
            },
        };
        vi.mocked(axios.get).mockResolvedValueOnce({ data });

        render(<ItReports />);

        const automation = await screen.findByText('Automation outcomes');
        const devices = screen.getByText('Device context');

        expect(screen.getAllByText('Restricted')).toHaveLength(2);
        expect(
            screen.getByText(
                'IT management access is required to view application-wide automation outcomes.',
            ),
        ).toBeVisible();
        expect(
            screen.getByText(
                'Security & Devices access is required to view canonical Device-linked counts.',
            ),
        ).toBeVisible();
        expect(automation.closest('a')).toBeNull();
        expect(devices.closest('a')).toBeNull();
        expect(screen.queryByText('No data to report yet')).toBeNull();
    });

    it.each(outcomeOnlyCases)(
        'does not hide %s behind the empty report state',
        async (_, mutate) => {
            const data = reportData({
                from: '2026-06-01',
                to: '2026-06-07',
                days: 7,
                open: 0,
            });
            mutate(data);
            vi.mocked(axios.get).mockResolvedValueOnce({ data });

            render(<ItReports />);

            expect(
                await screen.findByText('Operational outcomes'),
            ).toBeVisible();
            expect(screen.queryByText('No data to report yet')).toBeNull();
        },
    );

    it('cancels the prior range and ignores an out-of-order response', async () => {
        const thirtyDay = deferred<{ data: ReturnType<typeof reportData> }>();
        const sevenDay = deferred<{ data: ReturnType<typeof reportData> }>();
        vi.mocked(axios.get)
            .mockReturnValueOnce(thirtyDay.promise)
            .mockReturnValueOnce(sevenDay.promise);

        render(<ItReports />);

        expect(screen.getByRole('status')).toHaveTextContent(
            'Loading reports for the selected range',
        );
        fireEvent.click(screen.getByRole('button', { name: '7 days' }));

        await waitFor(() => expect(axios.get).toHaveBeenCalledTimes(2));
        const firstSignal = vi.mocked(axios.get).mock.calls[0][1]?.signal;
        expect(firstSignal?.aborted).toBe(true);

        await act(async () => {
            sevenDay.resolve({
                data: reportData({
                    from: '2026-06-01',
                    to: '2026-06-07',
                    days: 7,
                    open: 7,
                }),
            });
            await sevenDay.promise;
        });

        expect(await screen.findByText(/1 Jun.*7 Jun/)).toBeVisible();

        await act(async () => {
            thirtyDay.resolve({
                data: reportData({
                    from: '2026-05-09',
                    to: '2026-06-07',
                    days: 30,
                    open: 30,
                }),
            });
            await thirtyDay.promise;
        });

        expect(screen.getByText(/1 Jun.*7 Jun/)).toBeVisible();
        expect(screen.queryByText(/9 May.*7 Jun/)).not.toBeInTheDocument();
    });

    it('withholds prior data and shows an inline error when the selected range fails', async () => {
        const sevenDay = deferred<{ data: ReturnType<typeof reportData> }>();
        vi.mocked(axios.get)
            .mockResolvedValueOnce({
                data: reportData({
                    from: '2026-05-09',
                    to: '2026-06-07',
                    days: 30,
                    open: 30,
                }),
            })
            .mockReturnValueOnce(sevenDay.promise);

        render(<ItReports />);

        expect(await screen.findByText(/9 May.*7 Jun/)).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: '7 days' }));

        expect(
            await screen.findByText('Loading reports for the selected range…'),
        ).toBeVisible();
        expect(screen.queryByText(/9 May.*7 Jun/)).not.toBeInTheDocument();

        await act(async () => {
            sevenDay.reject(new Error('offline'));
            await sevenDay.promise.catch(() => undefined);
        });

        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent('Reports are unavailable');
        expect(alert).toHaveTextContent('Reports could not be loaded');
        expect(screen.queryByText(/9 May.*7 Jun/)).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Try again' })).toBeVisible();
    });
});
