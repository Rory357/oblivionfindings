import { fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import MyDay from './index';

const mocks = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    routerPost: vi.fn(),
    routerVisit: vi.fn(),
    routerPatch: vi.fn(),
    toastError: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: { href: string; children: React.ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: {
        post: mocks.routerPost,
        visit: mocks.routerVisit,
        patch: mocks.routerPatch,
    },
    usePage: () => ({ props: mocks.props }),
}));

vi.mock('sonner', () => ({
    toast: { error: mocks.toastError },
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({
        children,
        header,
    }: {
        children: React.ReactNode;
        header?: React.ReactNode;
    }) => (
        <div>
            {header}
            {children}
        </div>
    ),
}));

vi.mock('@/components/staff-header', () => ({
    StaffHeader: () => <header data-testid="staff-header" />,
}));

vi.mock('@/hooks/use-live-refresh', () => ({
    default: () => ({
        lastUpdatedAt: new Date('2026-06-08T10:00:00+12:00'),
        isRefreshing: false,
        refreshNow: vi.fn(),
    }),
}));

vi.mock('@/hooks/use-my-day-labels', () => ({
    useMyDayLabels: () => (key: string) => key,
}));

vi.mock('@/hooks/use-undoable-action', () => ({
    useUndoableAction: () => ({
        run: (callback: () => void) => callback(),
    }),
}));

vi.mock('@/components/end-of-shift-checklist', () => ({
    default: () => null,
}));

vi.mock('@/components/checklists/run-modal', () => ({
    RunModal: () => null,
}));

vi.mock('./components/my-day-header', () => ({
    MyDayHeader: () => <header data-testid="my-day-header" />,
}));

vi.mock('./components/day-work-list', () => ({
    DayWorkList: () => <section data-testid="day-work" />,
}));

vi.mock('./components/digest-panel', () => ({
    DigestPanel: () => <section data-testid="digest" />,
}));

vi.mock('./components/paperwork-panel', () => ({
    PaperworkPanel: () => <section data-testid="paperwork" />,
}));

vi.mock('./components/tomorrow-panel', () => ({
    TomorrowPanel: () => <section data-testid="tomorrow" />,
}));

vi.mock('./components/stream-context-menu', () => ({
    StreamContextMenu: () => null,
}));

vi.mock('./components/date-popover', () => ({
    DatePopover: () => null,
}));

vi.mock('./_dialogs', () => ({
    MealLogDialog: () => null,
    TimesheetReviewDialog: ({
        timesheet,
    }: {
        timesheet: { id: number } | null;
    }) => (timesheet ? <div>Timesheet #{timesheet.id}</div> : null),
    VitalsRecordDialog: () => null,
    WriteHandoverDialog: () => null,
}));

const baseProps = () => ({
    today: 'Monday, 8 June 2026',
    today_iso: '2026-06-08',
    shifts: [],
    medications_due: [],
    timesheets: [],
    incidents: [],
    tasks: [],
    stats: {
        shifts_today: 1,
        meds_due: 0,
        meds_overdue: 0,
        tasks_open: 0,
        timesheets_pending: 0,
        incidents_open: 0,
        cr_alerts: 0,
        notifications_unread: 0,
    },
    pending_claims_count: 0,
    leave: { balances: [], pending_requests: 0 },
    is_manager: false,
    clock: { can_clock: true, open_session: null },
    active_shift: {
        id: 44,
        starts_at: '2026-06-08T09:00:00+12:00',
        ends_at: '2026-06-08T17:00:00+12:00',
        status: 'scheduled',
        location: 'Rimu House',
        tasks: [],
        client: {
            id: 10,
            first_name: 'Mere',
            name: 'Mere Wilson',
            photo_url: null,
        },
        site: null,
    },
    active_round: null,
    shiftChecklists: [],
    checklistConfig: {
        categories: [
            { key: 'quality', label: 'Quality', icon: 'ClipboardCheck' },
        ],
        frequencyLabels: { daily: 'Daily' },
        typeLabels: {},
        today: '2026-06-08',
        can: { view: true, run: true },
    },
    runDetail: null,
    next_shift_briefing: null,
    previous_shift: null,
    handover: null,
    notifications: [],
    auth: {
        user: { id: 9, name: 'Sheila Worker', first_name: 'Sheila' },
        can: { timesheets: { create: true } },
    },
    can_record_observation: true,
    can_record_clinical: true,
});

describe('My Day audit wiring', () => {
    beforeEach(() => {
        mocks.routerPost.mockClear();
        mocks.routerVisit.mockClear();
        mocks.routerPatch.mockClear();
        mocks.toastError.mockClear();
        mocks.props = baseProps();
    });

    it('keeps an ordinary due task in the work list without a duplicate warning banner', () => {
        const base = baseProps();
        mocks.props = {
            ...base,
            active_shift: {
                ...base.active_shift,
                tasks: [
                    {
                        id: 5,
                        label: 'Prepare lunch',
                        client_id: 10,
                        is_completed: false,
                        scheduled_for: '2026-06-08T09:00:00+12:00',
                    },
                ],
            },
        };
        render(<MyDay />);
        expect(screen.getByTestId('day-work')).toBeVisible();
        expect(
            screen.queryByRole('button', { name: 'Review attention items' }),
        ).not.toBeInTheDocument();
    });

    it('keeps medication and other alerts prominent despite the simpler layout', () => {
        mocks.props = {
            ...baseProps(),
            medications_due: [
                {
                    id: 8,
                    client_id: 10,
                    client_name: 'Mere',
                    medication_name: 'Scheduled medication',
                    dose: '1',
                    scheduled_for: '2026-06-08T09:00:00+12:00',
                    status: 'overdue',
                },
            ],
        };
        render(<MyDay />);
        expect(
            screen.getByRole('button', { name: 'Review attention items' }),
        ).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Open medication list' }),
        ).toBeVisible();
    });

    it('routes Today timesheet through the ensure-today endpoint when no draft exists', () => {
        render(<MyDay />);

        fireEvent.click(
            screen.getByRole('button', { name: /review my timesheet/i }),
        );

        expect(mocks.routerPost).toHaveBeenCalledWith(
            '/my-tasks/timesheet/ensure-today',
            { shift_id: 44 },
            // F2 — now carries an onError handler so a "no shift today" response
            // surfaces a toast instead of looking like a dead button.
            expect.objectContaining({
                preserveScroll: true,
                onError: expect.any(Function),
            }),
        );
        expect(mocks.routerVisit).not.toHaveBeenCalledWith(
            expect.stringContaining('/operations/timesheets?create=1'),
        );
    });

    it('allows both My Day body columns to shrink below the desktop breakpoint', () => {
        render(<MyDay />);

        expect(screen.getByTestId('day-work').parentElement).toHaveClass(
            'min-w-0',
        );
        expect(screen.getByText('Your shift').closest('aside')).toHaveClass(
            'min-w-0',
        );
    });

    it('toasts the server error when ensure-today reports no shift today (F2)', () => {
        render(<MyDay />);

        fireEvent.click(
            screen.getByRole('button', { name: /review my timesheet/i }),
        );

        // Replay the backend's `back()->withErrors(['timesheet' => …])` through
        // the onError handler the page wired onto the request.
        const options = mocks.routerPost.mock.calls.at(-1)?.[2] as {
            onError?: (errors: Record<string, string>) => void;
        };
        options?.onError?.({
            timesheet: 'No shift today to write a timesheet against.',
        });

        expect(mocks.toastError).toHaveBeenCalledWith(
            'No shift today to write a timesheet against.',
        );
    });

    it('shows the active medication round resume banner on My Day', () => {
        mocks.props = {
            ...baseProps(),
            active_round: {
                id: 12,
                name: 'Morning round',
                status: 'in_progress',
                scheduled_time: '09:00',
                given: 4,
                total: 6,
                completed: 4,
                percent: 67,
                url: '/meds/rounds/12',
            },
        };

        render(<MyDay />);

        expect(
            screen.getByRole('link', { name: /resume morning round/i }),
        ).toHaveAttribute('href', '/meds/rounds/12');
        expect(screen.getByText(/4\s+of\s+6\s+done/)).toBeVisible();
    });

    it('shows a due checklist read-only when this worker cannot run it', () => {
        mocks.props = {
            ...baseProps(),
            active_shift: {
                ...(baseProps().active_shift as Record<string, unknown>),
                site: {
                    id: 3,
                    name: 'Rimu House',
                    type: 'House',
                    address: '12 Rimu Street',
                    href: '/sites/3',
                    residents: [],
                },
            },
            shiftChecklists: [
                {
                    id: 77,
                    status: 'scheduled',
                    can_run: false,
                    scheduled_date: '2026-06-08',
                    is_overdue: false,
                    pct: 25,
                    template: {
                        id: 5,
                        name: 'Kitchen reset',
                        frequency: 'daily',
                        category: 'quality',
                    },
                },
            ],
            checklistConfig: {
                ...baseProps().checklistConfig,
                can: { view: true, run: true },
            },
        };

        render(<MyDay />);

        expect(screen.getByText('Checklists due this shift')).toBeVisible();
        expect(screen.getByText('Kitchen reset')).toBeVisible();
        expect(screen.getByRole('button', { name: 'View' })).toBeVisible();
        expect(
            screen.queryByRole('button', { name: /complete/i }),
        ).not.toBeInTheDocument();
    });
});
