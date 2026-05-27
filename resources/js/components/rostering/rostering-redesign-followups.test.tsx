import { fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { describe, expect, it, vi } from 'vitest';

import AnalyticsPane from './analytics-pane';
import CapacityHeatmapPane from './capacity-heatmap-pane';
import OpenShiftsPane from './open-shifts-pane';
import ResolveConflictDialog from './resolve-conflict-dialog';
import WeekGridPane from './week-grid-pane';

vi.mock('@inertiajs/react', () => ({
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
}));

const weekDays = Array.from({ length: 7 }, (_, i) => {
    const d = new Date('2026-05-04T00:00:00');
    d.setDate(d.getDate() + i);
    return d;
});

describe('rostering redesign follow-up wiring', () => {
    it('surfaces active replacement requests separately from open shifts', () => {
        const onFindCover = vi.fn();

        render(
            <OpenShiftsPane
                stats={[]}
                shifts={[]}
                canManage
                onAssign={vi.fn()}
                replacementRequests={[
                    {
                        id: 91,
                        shift_id: 42,
                        requested_by: 'Mere Ana',
                        current_staff: 'Tama Rangi',
                        reason: 'Sick leave',
                        starts_at: '2026-05-04T21:00:00+12:00',
                        ends_at: '2026-05-05T07:00:00+12:00',
                        client: 'Ari Kauri',
                        location: 'Matai House',
                    },
                ]}
                onFindReplacement={onFindCover}
            />,
        );

        expect(screen.getByText('Replacement requests')).toBeVisible();
        expect(screen.getByText('Mere Ana')).toBeVisible();
        expect(screen.getByText(/Tama Rangi/)).toBeVisible();

        fireEvent.click(screen.getByRole('button', { name: /Find cover/i }));

        expect(onFindCover).toHaveBeenCalledWith(
            expect.objectContaining({ id: 91, shift_id: 42 }),
        );
    });

    it('shows detailed eligibility warning and blocker reasons in open shifts', () => {
        render(
            <OpenShiftsPane
                stats={[]}
                shifts={[]}
                canManage
                onAssign={vi.fn()}
                eligibilityAlerts={{
                    blocked: [
                        {
                            id: 4,
                            starts_at: '2026-05-04T09:00:00+12:00',
                            staff: 'Aroha King',
                            site: 'Matai House',
                            reason: 'Expired first aid certification',
                        },
                    ],
                    warnings: [
                        {
                            id: 5,
                            starts_at: '2026-05-05T09:00:00+12:00',
                            staff: 'Tama Rangi',
                            site: 'Kauri House',
                            reason: 'Tight turnaround from previous shift',
                        },
                    ],
                }}
            />,
        );

        expect(screen.getByText('Eligibility watchlist')).toBeVisible();
        expect(
            screen.getByText('Expired first aid certification'),
        ).toBeVisible();
        expect(
            screen.getByText('Tight turnaround from previous shift'),
        ).toBeVisible();
    });

    it('shows staff compliance state in the week grid and a clear empty roster state', () => {
        const { rerender } = render(
            <WeekGridPane
                days={weekDays}
                rows={[
                    {
                        id: 7,
                        name: 'Aroha King',
                        role: null,
                        initials: 'AK',
                        hue: 120,
                        complianceBadge: {
                            state: 'expired',
                            expired: 2,
                            expiring: 0,
                        },
                        shifts: {
                            '2026-05-04': [
                                {
                                    id: 44,
                                    status: 'scheduled',
                                    starts_at: '2026-05-04T09:00:00+12:00',
                                    ends_at: '2026-05-04T13:00:00+12:00',
                                    client: 'Ari Kauri',
                                },
                            ],
                        },
                    },
                ]}
                todayKey={null}
                canManage
            />,
        );

        expect(screen.getByText('Expired compliance')).toBeVisible();

        rerender(
            <WeekGridPane
                days={weekDays}
                rows={[]}
                todayKey={null}
                canManage
            />,
        );

        expect(screen.getByText(/No shifts this week/i)).toBeVisible();
    });

    it('shows compliance state on the capacity heatmap staff rows', () => {
        render(
            <CapacityHeatmapPane
                stats={[]}
                days={weekDays}
                todayKey={null}
                rows={[
                    {
                        id: 7,
                        name: 'Aroha King',
                        role: null,
                        initials: 'AK',
                        hue: 120,
                        days: [8, 8, 8, 8, 8, 0, 0],
                        target: 40,
                        complianceBadge: {
                            state: 'warning',
                            expired: 0,
                            expiring: 1,
                        },
                    },
                ]}
            />,
        );

        expect(screen.getByText('Expiring soon')).toBeVisible();
    });

    it('renders daily coverage returned by the controller in analytics', () => {
        render(
            <AnalyticsPane
                stats={[]}
                coverageTrend={[]}
                shiftTypes={[]}
                fillBySite={[]}
                overtimeTrend={[]}
                dailyCoverage={[
                    {
                        day: 'Mon',
                        date: '2026-05-04',
                        scheduled: 5,
                        filled: 4,
                        open: 1,
                    },
                    {
                        day: 'Tue',
                        date: '2026-05-05',
                        scheduled: 3,
                        filled: 3,
                        open: 0,
                    },
                ]}
            />,
        );

        expect(screen.getByText('Daily coverage')).toBeVisible();
        expect(screen.getByText('4/5 filled')).toBeVisible();
        expect(screen.getByText('1 open')).toBeVisible();
    });

    it('offers inline actions for resolving an overlapping shift', () => {
        const onUnassign = vi.fn();
        const onReassign = vi.fn();
        const onOpenQueue = vi.fn();

        render(
            <ResolveConflictDialog
                open
                shift={{
                    id: 44,
                    status: 'scheduled',
                    starts_at: '2026-05-04T09:00:00+12:00',
                    ends_at: '2026-05-04T13:00:00+12:00',
                    client: 'Ari Kauri',
                    staff: 'Aroha King',
                    href: '/operations/shifts/44',
                }}
                peers={[
                    {
                        id: 45,
                        status: 'scheduled',
                        starts_at: '2026-05-04T12:00:00+12:00',
                        ends_at: '2026-05-04T16:00:00+12:00',
                        client: 'Mere Rata',
                        staff: 'Aroha King',
                        href: '/operations/shifts/45',
                    },
                ]}
                onOpenChange={vi.fn()}
                onUnassign={onUnassign}
                onReassign={onReassign}
                onOpenQueue={onOpenQueue}
            />,
        );

        expect(screen.getByText('Resolve overlap')).toBeVisible();
        expect(screen.getByText('Ari Kauri')).toBeVisible();
        expect(screen.getByText('Mere Rata')).toBeVisible();

        fireEvent.click(screen.getAllByRole('button', { name: 'Unassign' })[0]);
        expect(onUnassign).toHaveBeenCalledWith(
            expect.objectContaining({ id: 44 }),
        );

        fireEvent.click(screen.getAllByRole('button', { name: 'Reassign' })[1]);
        expect(onReassign).toHaveBeenCalledWith(
            expect.objectContaining({ id: 45 }),
        );

        fireEvent.click(
            screen.getByRole('button', { name: /Open conflict queue/i }),
        );
        expect(onOpenQueue).toHaveBeenCalled();
    });
});
