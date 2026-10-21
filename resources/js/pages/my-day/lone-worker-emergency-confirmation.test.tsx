import { fireEvent, render, screen, within } from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import MyDay from './index';

// Lone worker safety: "I need help" must ask first. Nothing reaches the
// server until "Send emergency alert", and the request is the same emergency
// check-in POST as before. "I'm OK" still checks in straight away.

type VisitOptions = { onStart?: () => void; onFinish?: () => void };

const mocks = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    routerPost: vi.fn(
        (_url: string, _data: unknown, options?: VisitOptions) => {
            options?.onStart?.();
            options?.onFinish?.();
        },
    ),
    routerVisit: vi.fn(),
    routerPatch: vi.fn(),
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
    toast: { error: vi.fn() },
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

vi.mock('./components/date-popover', () => ({
    DatePopover: () => null,
}));

vi.mock('./_dialogs', () => ({
    MealLogDialog: () => null,
    TimesheetReviewDialog: () => null,
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
        categories: [],
        frequencyLabels: {},
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
        can: {},
    },
    active_lone_worker_session: {
        id: 15,
        status: 'active',
        started_at: '2026-06-08T09:00:00+12:00',
        expected_end_at: '2026-06-08T17:00:00+12:00',
        last_check_in_at: null,
        check_in_interval_minutes: 60,
        next_check_in_at: null,
        is_check_in_overdue: false,
        activity_description: null,
        site: { id: 3, name: 'Rimu House' },
        client: null,
    },
});

const checkInUrl = '/health-safety/lone-workers/sessions/15/check-in';
const dialog = () => screen.getByRole('alertdialog');
const emergencyPosts = () =>
    mocks.routerPost.mock.calls.filter(
        ([url, data]) =>
            url === checkInUrl &&
            (data as { status?: string })?.status === 'emergency',
    );

describe('My Day › lone worker emergency', () => {
    beforeEach(() => {
        mocks.routerPost.mockClear();
        mocks.props = baseProps();
    });

    it('asks before sending an emergency alert, and Cancel sends nothing', () => {
        render(<MyDay />);

        fireEvent.click(screen.getByRole('button', { name: /I need help/ }));

        expect(emergencyPosts()).toHaveLength(0);
        expect(
            within(dialog()).getByText('Send an emergency alert?'),
        ).toBeInTheDocument();
        expect(dialog()).toHaveTextContent(
            'The Control Room gets an urgent alert, and coordinators and managers are told straight away that you need help.',
        );
        // Frontline sizing for the staff surface.
        expect(dialog()).toHaveClass('frontline-dialog');

        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Cancel' }),
        );

        expect(emergencyPosts()).toHaveLength(0);
    });

    it('sends the emergency check-in once confirmed', () => {
        render(<MyDay />);

        fireEvent.click(screen.getByRole('button', { name: /I need help/ }));
        fireEvent.click(
            within(dialog()).getByRole('button', {
                name: 'Send emergency alert',
            }),
        );

        expect(emergencyPosts()).toHaveLength(1);
        expect(mocks.routerPost).toHaveBeenCalledWith(
            checkInUrl,
            { status: 'emergency' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it("still checks in straight away from I'm OK", () => {
        render(<MyDay />);

        fireEvent.click(screen.getByRole('button', { name: /I'm OK/ }));

        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
        expect(mocks.routerPost).toHaveBeenCalledWith(
            checkInUrl,
            { status: 'ok' },
            { preserveScroll: true },
        );
    });
});
