import { fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { describe, expect, it, vi } from 'vitest';

import AnalyticsPane from './analytics-pane';
import AvailabilityPane from './availability-pane';
import CapacityHeatmapPane from './capacity-heatmap-pane';
import CoveragePane from './coverage-pane';
import OpenShiftsPane from './open-shifts-pane';
import ReassignDialog from './reassign-dialog';
import ResolveConflictDialog from './resolve-conflict-dialog';
import TabStrip from './tab-strip';
import TimeOffPane from './time-off-pane';
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
                            user_id: 22,
                            starts_at: '2026-05-04T09:00:00+12:00',
                            staff: 'Aroha King',
                            site: 'Matai House',
                            reason: 'Expired first aid certification',
                        },
                    ],
                    warnings: [
                        {
                            id: 5,
                            user_id: 33,
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
        expect(
            screen.getByRole('link', {
                name: /Edit availability for Aroha King/i,
            }),
        ).toHaveAttribute('href', '/staff/22/availability');
    });

    it('moves rostering tabs with arrow keys', () => {
        const onChange = vi.fn();

        render(
            <TabStrip
                value="shifts"
                onChange={onChange}
                items={[
                    {
                        id: 'shifts',
                        label: 'Shifts',
                        icon: (() => <span />) as React.ComponentType<{
                            className?: string;
                        }>,
                        tone: 'primary',
                    },
                    {
                        id: 'open',
                        label: 'Open',
                        icon: (() => <span />) as React.ComponentType<{
                            className?: string;
                        }>,
                        tone: 'warning',
                    },
                    {
                        id: 'availability',
                        label: 'Availability',
                        icon: (() => <span />) as React.ComponentType<{
                            className?: string;
                        }>,
                        tone: 'success',
                    },
                ]}
            />,
        );

        fireEvent.keyDown(screen.getByRole('tab', { name: /Shifts/i }), {
            key: 'ArrowRight',
        });

        expect(onChange).toHaveBeenCalledWith('open');
    });

    it('shows staff availability in a rostering pane with an inline edit button', () => {
        render(
            <AvailabilityPane
                canManage
                staff={[
                    {
                        id: 22,
                        name: 'Aroha King',
                        email: 'aroha@example.test',
                        role: 'support_worker',
                        staff_availability: [
                            {
                                id: 1,
                                day_of_week: new Date().getDay(),
                                start_time: '09:00',
                                end_time: '17:00',
                            },
                        ],
                        staff_time_off: [],
                    },
                ]}
                upcomingLeave={{
                    22: [
                        {
                            id: 10,
                            leave_type: 'annual_leave',
                            starts_at: '2026-05-06T00:00:00+12:00',
                            ends_at: '2026-05-08T23:59:00+12:00',
                            status: 'approved',
                        },
                    ],
                }}
            />,
        );

        expect(screen.getByText('Declared today')).toBeVisible();
        expect(screen.getByText('Aroha King')).toBeVisible();
        // Edit no longer navigates — it opens an in-page dialog.
        expect(
            screen.getByRole('button', { name: /Edit availability/i }),
        ).toBeVisible();
    });

    it('shows candidate capacity context on suggested staff chips', () => {
        const onAssign = vi.fn();

        render(
            <OpenShiftsPane
                stats={[]}
                canManage
                onAssign={onAssign}
                shifts={[
                    {
                        id: 44,
                        day: 'Mon 04 May',
                        start: '09:00',
                        end: '13:00',
                        hours: 4,
                        client: 'Ari Kauri',
                        site: 'Matai House',
                        reason: null,
                        eligible: 1,
                        warnings: 0,
                        suggestions: [{ id: 7, name: 'Aroha King', hours: 32 }],
                        href: '/operations/shifts/44',
                    },
                ]}
            />,
        );

        expect(screen.getByText('32h this week')).toBeVisible();

        fireEvent.click(screen.getByRole('button', { name: /Aroha King/i }));
        expect(onAssign).toHaveBeenCalledWith(
            expect.objectContaining({ id: 44 }),
            7,
        );
    });

    it('renders a warning indicator and tooltip on suggestion chips when a candidate has soft eligibility issues', () => {
        const onAssign = vi.fn();

        render(
            <OpenShiftsPane
                stats={[]}
                canManage
                onAssign={onAssign}
                shifts={[
                    {
                        id: 44,
                        day: 'Mon 04 May',
                        start: '09:00',
                        end: '13:00',
                        hours: 4,
                        client: 'Ari Kauri',
                        site: 'Matai House',
                        reason: null,
                        eligible: 1,
                        warnings: 1,
                        suggestions: [
                            {
                                id: 7,
                                name: 'Aroha King',
                                hours: 32,
                                eligibility: {
                                    status: 'warning',
                                    reasons: ['Tight turnaround under 8h'],
                                },
                            },
                        ],
                        href: '/operations/shifts/44',
                    },
                ]}
            />,
        );

        const chip = screen.getByRole('button', { name: /Aroha King/i });
        expect(chip).toHaveAttribute('data-eligibility', 'warning');
        expect(chip).toHaveAttribute('title', 'Tight turnaround under 8h');
        expect(chip).not.toBeDisabled();

        fireEvent.click(chip);
        expect(onAssign).toHaveBeenCalledWith(
            expect.objectContaining({ id: 44 }),
            7,
        );
    });

    it('renders a blocked chip that cannot be clicked when a candidate fails hard eligibility', () => {
        const onAssign = vi.fn();

        render(
            <OpenShiftsPane
                stats={[]}
                canManage
                onAssign={onAssign}
                shifts={[
                    {
                        id: 44,
                        day: 'Mon 04 May',
                        start: '09:00',
                        end: '13:00',
                        hours: 4,
                        client: 'Ari Kauri',
                        site: 'Matai House',
                        reason: null,
                        eligible: 0,
                        warnings: 0,
                        suggestions: [
                            {
                                id: 7,
                                name: 'Aroha King',
                                hours: 32,
                                eligibility: {
                                    status: 'blocked',
                                    reasons: [
                                        'This staff member is already marked unavailable during this time.',
                                    ],
                                },
                            },
                        ],
                        href: '/operations/shifts/44',
                    },
                ]}
            />,
        );

        const chip = screen.getByRole('button', { name: /Aroha King/i });
        expect(chip).toHaveAttribute('data-eligibility', 'blocked');
        expect(chip).toBeDisabled();

        fireEvent.click(chip);
        expect(onAssign).not.toHaveBeenCalled();
    });

    it('keeps eligible chips unstyled and clickable when no eligibility entry is provided', () => {
        const onAssign = vi.fn();

        render(
            <OpenShiftsPane
                stats={[]}
                canManage
                onAssign={onAssign}
                shifts={[
                    {
                        id: 44,
                        day: 'Mon 04 May',
                        start: '09:00',
                        end: '13:00',
                        hours: 4,
                        client: 'Ari Kauri',
                        site: 'Matai House',
                        reason: null,
                        eligible: 1,
                        warnings: 0,
                        suggestions: [{ id: 7, name: 'Aroha King', hours: 32 }],
                        href: '/operations/shifts/44',
                    },
                ]}
            />,
        );

        const chip = screen.getByRole('button', { name: /Aroha King/i });
        expect(chip).toHaveAttribute('data-eligibility', 'eligible');
        expect(chip).not.toBeDisabled();

        fireEvent.click(chip);
        expect(onAssign).toHaveBeenCalled();
    });

    it('does not show an unsupported broadcast action for open shifts', () => {
        render(
            <OpenShiftsPane
                stats={[]}
                canManage
                onAssign={vi.fn()}
                shifts={[
                    {
                        id: 44,
                        day: 'Mon 04 May',
                        start: '09:00',
                        end: '13:00',
                        hours: 4,
                        client: 'Ari Kauri',
                        site: 'Matai House',
                        reason: 'vacant',
                        eligible: 1,
                        warnings: 0,
                        suggestions: [],
                        href: '/operations/shifts/44',
                    },
                ]}
            />,
        );

        expect(
            screen.queryByRole('button', { name: /Broadcast/i }),
        ).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: /View/i })).toBeVisible();
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

    it('offers a guarded reopen action for cancelled shifts in the week grid menu', () => {
        const onReopenShift = vi.fn();

        render(
            <WeekGridPane
                days={weekDays}
                rows={[
                    {
                        id: 7,
                        name: 'Aroha King',
                        role: null,
                        initials: 'AK',
                        hue: 120,
                        shifts: {
                            '2026-05-04': [
                                {
                                    id: 44,
                                    status: 'cancelled',
                                    starts_at: '2026-05-04T09:00:00+12:00',
                                    ends_at: '2026-05-04T13:00:00+12:00',
                                    client: 'Ari Kauri',
                                    href: '/operations/shifts/44',
                                },
                            ],
                        },
                    },
                ]}
                todayKey={null}
                canManage
                onReopenShift={onReopenShift}
            />,
        );

        fireEvent.contextMenu(screen.getByText('Ari Kauri'));

        expect(
            screen.getByRole('menuitem', { name: /Reopen cancelled shift/i }),
        ).toBeVisible();
        expect(
            screen.queryByRole('menuitem', { name: /Cancel shift/i }),
        ).not.toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('menuitem', { name: /Reopen cancelled shift/i }),
        );
        expect(onReopenShift).toHaveBeenCalledWith(
            expect.objectContaining({ id: 44, status: 'cancelled' }),
        );
    });

    it('offers a copy-to-day action alongside duplicate for editable shifts', () => {
        const onCopyShiftToDay = vi.fn();

        render(
            <WeekGridPane
                days={weekDays}
                rows={[
                    {
                        id: 7,
                        name: 'Aroha King',
                        role: null,
                        initials: 'AK',
                        hue: 120,
                        shifts: {
                            '2026-05-04': [
                                {
                                    id: 44,
                                    status: 'scheduled',
                                    starts_at: '2026-05-04T09:00:00+12:00',
                                    ends_at: '2026-05-04T13:00:00+12:00',
                                    client: 'Ari Kauri',
                                    href: '/operations/shifts/44',
                                },
                            ],
                        },
                    },
                ]}
                todayKey={null}
                canManage
                onCopyShiftToDay={onCopyShiftToDay}
            />,
        );

        fireEvent.contextMenu(screen.getByText('Ari Kauri'));

        fireEvent.click(
            screen.getByRole('menuitem', { name: /Copy to day…/i }),
        );
        expect(onCopyShiftToDay).toHaveBeenCalledWith(
            expect.objectContaining({ id: 44, status: 'scheduled' }),
        );
    });

    it('offers a duplicate action for editable scheduled shifts', () => {
        const onDuplicateShift = vi.fn();

        render(
            <WeekGridPane
                days={weekDays}
                rows={[
                    {
                        id: 7,
                        name: 'Aroha King',
                        role: null,
                        initials: 'AK',
                        hue: 120,
                        shifts: {
                            '2026-05-04': [
                                {
                                    id: 44,
                                    status: 'scheduled',
                                    starts_at: '2026-05-04T09:00:00+12:00',
                                    ends_at: '2026-05-04T13:00:00+12:00',
                                    client: 'Ari Kauri',
                                    href: '/operations/shifts/44',
                                },
                            ],
                        },
                    },
                ]}
                todayKey={null}
                canManage
                onDuplicateShift={onDuplicateShift}
            />,
        );

        fireEvent.contextMenu(screen.getByText('Ari Kauri'));

        fireEvent.click(
            screen.getByRole('menuitem', { name: /Duplicate as draft/i }),
        );
        expect(onDuplicateShift).toHaveBeenCalledWith(
            expect.objectContaining({ id: 44, status: 'scheduled' }),
        );
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

    it('surfaces top-level coverage alerts returned by the controller', () => {
        render(
            <CoveragePane
                stats={[]}
                windowLabels={['AM 07-15']}
                rows={[]}
                alerts={[
                    {
                        site_name: 'Matai House',
                        rule_name: 'Night cover',
                        window_label: 'Night 23-07',
                        required_staff: 2,
                        assigned_staff: 1,
                        missing_staff: 1,
                        coverage_state: 'gap',
                    },
                ]}
            />,
        );

        expect(screen.getByText('Coverage gaps this week')).toBeVisible();
        expect(screen.getByText('Matai House')).toBeVisible();
        expect(screen.getByText('Night cover')).toBeVisible();
        expect(screen.getByText('1 short')).toBeVisible();
        expect(screen.getByText('1/2 assigned')).toBeVisible();
    });

    it('uses caught-up copy when no leave requests are waiting', () => {
        render(
            <TimeOffPane
                stats={[]}
                requests={[]}
                weekStart={new Date('2026-05-04T00:00:00')}
                canManage
            />,
        );

        expect(
            screen.getByText('All caught up · no pending requests'),
        ).toBeVisible();
        expect(
            screen.queryByText('Awaiting your decision · oldest first'),
        ).not.toBeInTheDocument();
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

    it('switches the week grid between week, day and list layouts', () => {
        const rows = [
            {
                id: 7,
                name: 'Aroha King',
                role: null,
                initials: 'AK',
                hue: 120,
                shifts: {
                    '2026-05-04': [
                        {
                            id: 44,
                            status: 'scheduled' as const,
                            starts_at: '2026-05-04T09:00:00',
                            ends_at: '2026-05-04T13:00:00',
                            client: 'Ari Kauri',
                            href: '/operations/shifts/44',
                        },
                    ],
                },
            },
        ];

        render(
            <WeekGridPane
                days={weekDays}
                rows={rows}
                todayKey={null}
                canManage
            />,
        );

        // Default week view shows the staff-row header.
        expect(screen.getByText(/rostered/i)).toBeVisible();

        // List view collapses to a chronological stream with a per-day count.
        fireEvent.click(screen.getByRole('tab', { name: 'List' }));
        expect(screen.getByText('1 shift')).toBeVisible();

        // Day view exposes the single-day stepper controls.
        fireEvent.click(screen.getByRole('tab', { name: 'Day' }));
        expect(screen.getByRole('button', { name: /Prev day/i })).toBeVisible();
        expect(screen.getByRole('button', { name: /Next day/i })).toBeVisible();
    });

    it('regroups the week grid between staff and site rows', () => {
        const cell = [
            {
                id: 44,
                status: 'scheduled' as const,
                starts_at: '2026-05-04T09:00:00',
                ends_at: '2026-05-04T13:00:00',
                client: 'Ari Kauri',
                staff: 'Aroha King',
                href: '/operations/shifts/44',
            },
        ];
        const staffRows = [
            {
                id: 7,
                name: 'Aroha King',
                role: null,
                initials: 'AK',
                hue: 120,
                shifts: { '2026-05-04': cell },
            },
        ];
        const siteRows = [
            {
                id: 3,
                name: 'Matai House',
                role: null,
                initials: 'MH',
                hue: 200,
                shifts: { '2026-05-04': cell },
            },
        ];

        render(
            <WeekGridPane
                days={weekDays}
                rows={staffRows}
                siteRows={siteRows}
                todayKey={null}
                canManage
            />,
        );

        // Defaults to grouping by staff.
        expect(screen.getByText(/Staff ·/i)).toBeVisible();
        expect(screen.getByText('Aroha King')).toBeVisible();

        // Switching to Site regroups the same grid into site rows.
        fireEvent.click(screen.getByRole('tab', { name: 'Site' }));
        expect(screen.getByText(/Sites ·/i)).toBeVisible();
        expect(screen.getByText('Matai House')).toBeVisible();
    });

    it('reassigns from a popup of eligibility-ranked candidates, with an override step for warnings', async () => {
        const onAssign = vi.fn();
        const originalFetch = global.fetch;
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                candidates: [
                    {
                        id: 7,
                        name: 'Aroha King',
                        weekly_hours: 32,
                        is_eligible: true,
                        blocked_reasons: [],
                        warning_reasons: [],
                    },
                    {
                        id: 8,
                        name: 'Tama Rangi',
                        weekly_hours: 12,
                        is_eligible: true,
                        blocked_reasons: [],
                        warning_reasons: ['Tight turnaround under 8h'],
                    },
                    {
                        id: 9,
                        name: 'Mere Ana',
                        weekly_hours: 40,
                        is_eligible: false,
                        blocked_reasons: ['Expired first aid certification'],
                        warning_reasons: [],
                    },
                ],
                current_user_id: null,
            }),
        }) as unknown as typeof fetch;

        try {
            render(
                <ReassignDialog
                    open
                    shift={{
                        id: 44,
                        starts_at: '2026-05-04T09:00:00',
                        ends_at: '2026-05-04T13:00:00',
                        client: 'Ari Kauri',
                        staff: null,
                        isOpen: true,
                    }}
                    onOpenChange={vi.fn()}
                    onAssign={onAssign}
                />,
            );

            // Eligible candidate assigns directly.
            const aroha = await screen.findByRole('button', {
                name: /Aroha King/i,
            });
            fireEvent.click(aroha);
            expect(onAssign).toHaveBeenCalledWith(44, 7);

            // Blocked candidate cannot be picked.
            expect(
                screen.getByRole('button', { name: /Mere Ana/i }),
            ).toBeDisabled();

            // Warning candidate requires an override reason before assigning.
            fireEvent.click(screen.getByRole('button', { name: /Tama Rangi/i }));
            expect(
                screen.getByText(/has eligibility warnings/i),
            ).toBeVisible();
            fireEvent.change(
                screen.getByLabelText(/Reason for overriding/i),
                { target: { value: 'Covered by senior on site' } },
            );
            fireEvent.click(
                screen.getByRole('button', { name: /Assign anyway/i }),
            );
            expect(onAssign).toHaveBeenCalledWith(44, 8, {
                reason: 'Covered by senior on site',
            });
        } finally {
            global.fetch = originalFetch;
        }
    });
});
