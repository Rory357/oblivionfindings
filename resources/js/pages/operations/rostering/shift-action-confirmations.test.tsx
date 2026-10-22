import { fireEvent, render, screen, within } from '@testing-library/react';
import type { PropsWithChildren } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import RosteringIndex from './index';

// Rostering grid/calendar actions — cancel, duplicate, auto-fill, publish and
// reopen — must each ask first. Nothing reaches the server until the confirm
// button, and each confirm sends the same request as before.
//
// The page is large, so its panes and dialogs are stubbed. The stub week grid
// exposes one button per shift action that calls the page's real callback
// with the shift under test.

type VisitOptions = { onStart?: () => void; onFinish?: () => void };
type StubShift = {
    id: number;
    status: string;
    starts_at: string;
    ends_at: string;
    client: string | null;
    staff?: string | null;
};
type GridCallbacks = Record<string, ((shift: StubShift) => void) | undefined>;

const grid = vi.hoisted(() => ({
    shift: null as unknown as StubShift,
}));

const router = vi.hoisted(() => {
    const settle = (_url: string, _data: unknown, options?: VisitOptions) => {
        options?.onStart?.();
        options?.onFinish?.();
    };
    return {
        patch: vi.fn(settle),
        post: vi.fn(settle),
        get: vi.fn(),
        visit: vi.fn(),
        reload: vi.fn(),
    };
});

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children }: PropsWithChildren) => <>{children}</>,
    usePage: () => ({
        url: '/operations/rostering',
        props: {
            auth: {
                user: { name: 'Ana Lead' },
                can: { shifts: { manageAny: true } },
            },
        },
    }),
    router,
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: PropsWithChildren) => <>{children}</>,
}));
vi.mock('@/components/page', () => {
    const Nothing = () => null;
    return {
        PageHeaderFilterButton: Nothing,
        PageHeaderGlassButton: Nothing,
        PageHeaderMeterBig: Nothing,
        PageHeaderMeterBlock: Nothing,
        PageHeaderMeterCaption: Nothing,
        PageHeaderMeterDonut: Nothing,
        PageHeaderPrimaryButton: Nothing,
        PageHeaderSearch: Nothing,
        PageHeaderStatusChip: Nothing,
        PageLayout: ({ children }: PropsWithChildren) => <>{children}</>,
    };
});
vi.mock('@/components/workforce/workforce-page-header', () => ({
    WorkforcePageHeader: () => null,
}));
vi.mock('@/routes/operations/rostering', () => ({
    index: Object.assign(() => ({ url: '/operations/rostering' }), {
        url: () => '/operations/rostering',
    }),
}));
vi.mock('../shifts/components/create-shift-dialog', () => ({
    CreateShiftDialog: () => null,
}));
vi.mock('@/components/timesheets/view-timesheet-dialog', () => ({
    default: () => null,
}));
vi.mock('@/components/rostering/eligibility-refresh-panel', () => ({
    EligibilityRefreshPanel: () => null,
}));
vi.mock('@/components/rostering/template-library', () => ({
    TemplateLibrary: () => null,
}));
vi.mock('@/components/rostering/roster-shift-list-url', () => ({
    rosterShiftListUrl: () => '/operations/shifts',
}));
vi.mock('@/components/rostering/roster-workspace-navigation', () => ({
    RosterWorkspaceRail: () => null,
    RosterWorkspaceViewSelect: () => null,
}));
vi.mock('@/components/rostering/use-template-command', () => ({
    TEMPLATE_READ_KEYS: [],
}));
vi.mock('@/components/rostering', async () => {
    const weekPicker = await vi.importActual<
        typeof import('@/components/rostering/week-picker')
    >('@/components/rostering/week-picker');
    const Nothing = () => null;
    const actions: Array<[string, string]> = [
        ['onCancelShift', 'Grid: cancel'],
        ['onDuplicateShift', 'Grid: duplicate'],
        ['onAutoFillShift', 'Grid: auto-fill'],
        ['onPublishShift', 'Grid: publish'],
        ['onReopenShift', 'Grid: reopen'],
    ];
    return {
        WeekGridPane: (props: GridCallbacks) => (
            <div>
                {actions.map(([callback, label]) => (
                    // eslint-disable-next-line no-restricted-syntax -- week grid test double; production uses the real grid menu.
                    <button
                        key={callback}
                        type="button"
                        onClick={() => props[callback]?.(grid.shift)}
                    >
                        {label}
                    </button>
                ))}
            </div>
        ),
        AnalyticsPane: Nothing,
        AvailabilityPane: Nothing,
        BroadcastDialog: Nothing,
        CalendarPane: Nothing,
        CapacityHeatmapPane: Nothing,
        CopyToDayDialog: Nothing,
        CoveragePane: Nothing,
        EntityFilter: Nothing,
        MakeRecurringDialog: Nothing,
        MarkEndedEarlyDialog: Nothing,
        OpenShiftsPane: Nothing,
        ReassignDialog: Nothing,
        ReopenForCorrectionDialog: Nothing,
        RequestReplacementDialog: Nothing,
        ResolveConflictDialog: Nothing,
        SeriesDetailDialog: Nothing,
        SeriesPane: Nothing,
        SignalRail: Nothing,
        SiteFilter: Nothing,
        TimeOffPane: Nothing,
        UnassignMakeOpenDialog: Nothing,
        WeekPicker: Nothing,
        addDaysWP: weekPicker.addDaysWP,
        formatWeekRange: weekPicker.formatWeekRange,
        startOfWeek: weekPicker.startOfWeek,
        weekLabel: weekPicker.weekLabel,
    };
});

const props = () =>
    ({
        canManageAny: true,
        canApproveLeave: false,
        canPublishRoster: false,
        canAutoScheduleRoster: false,
        rosteringFeatures: { publish: false, auto_schedule: false },
        weekStart: '2026-10-19',
        weekEnd: '2026-10-25',
        filters: {
            week: '2026-10-19',
            staff_id: null,
            client_id: null,
            site_id: null,
            site_ids: [],
        },
        staff: [],
        clients: [],
        sites: [],
        rosterPeriod: null,
        stats: {
            total: 0,
            open: 0,
            draft: 0,
            scheduled: 0,
            in_progress: 0,
            completed: 0,
            cancelled: 0,
            incidents: 0,
            staff_overlaps: 0,
            client_overlaps: 0,
            timesheets_pending: 0,
            time_off_conflicts: 0,
            coverage_gaps: 0,
        },
        shifts: [],
        replacementQueue: [],
        coverageSites: [],
        coverageAlerts: [],
        timeOffs: [],
        capacity: [],
        analytics: {
            dailyCoverage: [],
            shiftTypeDistribution: [],
            historicalTrend: [],
            coverageRate: 0,
            staffRostered: 0,
            onLeaveCount: 0,
            complianceExpiring: 0,
            complianceExpired: 0,
        },
        eligibilityAlerts: {
            counts: { eligible: 0, warnings: 0, blocked: 0, overrides: 0 },
            blocked: [],
            warnings: [],
        },
    }) as unknown as Parameters<typeof RosteringIndex>[0];

const assigned: StubShift = {
    id: 501,
    status: 'scheduled',
    starts_at: '2026-10-20T20:00:00Z',
    ends_at: '2026-10-21T04:00:00Z',
    client: 'Mere Tane',
    staff: 'Sam Worker',
};
const open: StubShift = { ...assigned, id: 502, status: 'open', staff: null };

const dialog = () => screen.getByRole('alertdialog');
const confirm = (name: string) =>
    fireEvent.click(within(dialog()).getByRole('button', { name }));

beforeEach(() => {
    router.patch.mockClear();
    router.post.mockClear();
    grid.shift = assigned;
});

describe('Rostering › Cancel shift', () => {
    it('asks first, names the shift and its effects, and Keep shift sends nothing', () => {
        render(<RosteringIndex {...props()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Grid: cancel' }));

        expect(router.patch).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Cancel this shift?'),
        ).toBeInTheDocument();
        expect(dialog()).toHaveTextContent(
            'Wed 21 Oct, 9:00 am – 5:00 pm · Mere Tane · Sam Worker',
        );
        expect(dialog()).toHaveTextContent(
            'Sam Worker isn’t sent a notification, so let them know.',
        );

        confirm('Keep shift');

        expect(router.patch).not.toHaveBeenCalled();
    });

    it('cancels the shift once confirmed', () => {
        render(<RosteringIndex {...props()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Grid: cancel' }));
        confirm('Cancel shift');

        expect(router.patch).toHaveBeenCalledTimes(1);
        expect(router.patch).toHaveBeenCalledWith(
            '/operations/shifts/501/cancel',
            { return_to: '/operations/rostering' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });
});

describe('Rostering › Duplicate shift', () => {
    it('asks first, then duplicates the shift once confirmed', () => {
        render(<RosteringIndex {...props()} />);

        fireEvent.click(
            screen.getByRole('button', { name: 'Grid: duplicate' }),
        );

        expect(router.post).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Duplicate this shift?'),
        ).toBeInTheDocument();
        expect(dialog()).toHaveTextContent('as an unassigned draft');

        confirm('Duplicate shift');

        expect(router.post).toHaveBeenCalledWith(
            '/operations/shifts/501/duplicate',
            { return_to: '/operations/rostering' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('sends nothing when cancelled', () => {
        render(<RosteringIndex {...props()} />);

        fireEvent.click(
            screen.getByRole('button', { name: 'Grid: duplicate' }),
        );
        confirm('Cancel');

        expect(router.post).not.toHaveBeenCalled();
    });
});

describe('Rostering › Auto-fill open shift', () => {
    it('asks first, says how the staff member is picked, then auto-fills', () => {
        grid.shift = open;
        render(<RosteringIndex {...props()} />);

        fireEvent.click(
            screen.getByRole('button', { name: 'Grid: auto-fill' }),
        );

        expect(router.post).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Auto-fill this open shift?'),
        ).toBeInTheDocument();
        expect(dialog()).toHaveTextContent(
            'The first staff member, in name order, who can work this shift with no eligibility warnings is assigned to it.',
        );

        confirm('Auto-fill shift');

        expect(router.post).toHaveBeenCalledWith(
            '/operations/shifts/502/auto-fill',
            { return_to: '/operations/rostering' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('sends nothing when cancelled', () => {
        grid.shift = open;
        render(<RosteringIndex {...props()} />);

        fireEvent.click(
            screen.getByRole('button', { name: 'Grid: auto-fill' }),
        );
        confirm('Cancel');

        expect(router.post).not.toHaveBeenCalled();
    });
});

describe('Rostering › Publish shift', () => {
    it('asks first, says who will see it, then publishes', () => {
        grid.shift = { ...assigned, status: 'draft' };
        render(<RosteringIndex {...props()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Grid: publish' }));

        expect(router.patch).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Publish this shift?'),
        ).toBeInTheDocument();
        expect(dialog()).toHaveTextContent(
            'Sam Worker will see it in their roster. Nobody is sent a notification.',
        );

        confirm('Publish shift');

        expect(router.patch).toHaveBeenCalledWith(
            '/operations/shifts/501/publish',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('describes an unassigned draft as an open shift, and Cancel sends nothing', () => {
        grid.shift = { ...open, status: 'draft' };
        render(<RosteringIndex {...props()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Grid: publish' }));

        expect(dialog()).toHaveTextContent(
            'It’s published as an open shift, ready for someone to be assigned.',
        );

        confirm('Cancel');

        expect(router.patch).not.toHaveBeenCalled();
    });
});

describe('Rostering › Reopen cancelled shift', () => {
    it('asks first, then reopens the shift once confirmed', () => {
        grid.shift = { ...assigned, status: 'cancelled' };
        render(<RosteringIndex {...props()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Grid: reopen' }));

        expect(router.patch).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Reopen this shift?'),
        ).toBeInTheDocument();
        expect(dialog()).toHaveTextContent(
            'It goes back on the roster with Sam Worker still assigned',
        );

        confirm('Reopen shift');

        expect(router.patch).toHaveBeenCalledWith(
            '/operations/shifts/501/reopen',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('sends nothing when cancelled', () => {
        grid.shift = { ...assigned, status: 'cancelled' };
        render(<RosteringIndex {...props()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Grid: reopen' }));
        confirm('Cancel');

        expect(router.patch).not.toHaveBeenCalled();
    });
});
