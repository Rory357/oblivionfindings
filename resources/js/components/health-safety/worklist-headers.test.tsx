import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { type ComponentProps, type ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';

const state = vi.hoisted(() => ({
    get: vi.fn(),
    visit: vi.fn(),
    post: vi.fn(),
    reports: true,
    url: '/health-safety/events?site_id=1&q=HS',
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        children,
        href,
        ...props
    }: {
        children: ReactNode;
        href: string;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    usePage: () => ({
        url: state.url,
        props: {
            auth: {
                can: {
                    hazards: { view: true },
                    governance: { view: state.reports },
                },
            },
        },
    }),
    router: { get: state.get, visit: state.visit, post: state.post },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
vi.mock('@/components/health-safety/event-detail-dialog', async (original) => ({
    ...(await original<
        typeof import('@/components/health-safety/event-detail-dialog')
    >()),
    EventDetailDialog: () => null,
}));

import Actions, {
    type ActionRow,
} from '@/pages/health-safety/corrective-actions/index';
import Reviews, {
    EventTable,
    type EventRow,
} from '@/pages/health-safety/events/index';

function reviewProps(): ComponentProps<typeof Reviews> {
    return {
        events: { data: [], links: [], last_page: 1 },
        tab: 'all',
        tabCounts: {
            all: 8,
            investigating: 3,
            corrective_actions: 2,
            closed: 1,
        },
        hero: {
            live: {
                open: 2,
                investigating: 3,
                corrective_action: 2,
                monitoring: 1,
            },
            attention: {
                investigation_due: 2,
                awaiting_verification: 1,
                worksafe_due: 1,
                handover_due: 1,
                closed_period: 1,
            },
        },
        filters: {
            q: 'HS',
            tab: 'all',
            severity: 'high',
            category: 'incident',
            source: 'incidents',
            site_id: 1,
            worksafe: null,
            from: '2026-10-01',
            to: '2026-10-08',
        },
        sites: [{ id: 1, name: 'Kauri House' }],
        detail: null,
        can: { manage: true },
    };
}
function actionProps(): ComponentProps<typeof Actions> {
    return {
        actions: {
            data: [],
            links: [],
            last_page: 1,
            total: 0,
            from: null,
            to: null,
        },
        tab: 'all',
        tabCounts: { all: 9, in_progress: 3, verified: 2, critical_open: 2 },
        hero: {
            live: {
                open: 3,
                in_progress: 3,
                awaiting_verification: 1,
                verified: 2,
            },
            attention: {
                overdue: 2,
                critical_open: 2,
                unassigned: 1,
                monitoring_events: 2,
            },
        },
        filters: {
            q: 'Needle',
            tab: 'all',
            priority: 'high',
            unassigned: true,
            site_id: 1,
            from: '2026-10-01',
            to: '2026-10-31',
        },
        sites: [{ id: 1, name: 'Kauri House' }],
        detail: null,
        can: { manage: true, viewReports: true },
        monitoringReviewUrl:
            '/health-safety/events?tab=monitoring&action_context%5Bq%5D=Needle&action_context%5Bfrom%5D=2026-10-01',
    };
}
afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    state.reports = true;
    state.url = '/health-safety/events?site_id=1&q=HS';
});

it('uses one review header, four linked numbers and the shared sibling rail', () => {
    const props = reviewProps();
    render(<Reviews {...props} />);
    expect(document.querySelectorAll('header.eh-header')).toHaveLength(1);
    expect(screen.queryByLabelText('Safety workflow')).not.toBeInTheDocument();
    expect(
        screen.getByRole('tablist', { name: 'Incidents and safety reviews views' }),
    ).toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: /^View / })).toHaveLength(4);
    fireEvent.click(
        screen.getByRole('button', { name: 'View investigation needed' }),
    );
    expect(state.get).toHaveBeenCalledWith(
        '/health-safety/events',
        { ...props.filters, tab: 'investigation_due' },
        { preserveScroll: true },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Review view' }));
    expect(screen.getByText('Being investigated (3)')).toBeInTheDocument();
    expect(screen.getByText('Actions under way (2)')).toBeInTheDocument();
    expect(screen.getByText('Closed (1)')).toBeInTheDocument();
});

it('links to readable report pages and hides the report launcher without permission', () => {
    const rendered = render(<Reviews {...reviewProps()} />);
    fireEvent.click(screen.getByRole('button', { name: 'Board reports' }));
    fireEvent.click(screen.getByRole('button', { name: 'WorkSafe notifications' }));
    expect(state.visit).toHaveBeenCalledWith(
        '/health-safety/reports?view=notifications',
    );
    rendered.unmount();
    state.reports = false;
    render(<Reviews {...reviewProps()} />);
    expect(
        screen.queryByRole('button', { name: 'Board reports' }),
    ).not.toBeInTheDocument();
});

it('explains retained action filters without treating due dates as review occurrence dates', () => {
    const props = reviewProps();
    props.filters.action_context = {
        q: 'Needle',
        priority: 'high',
        site_id: 1,
        from: '2026-10-01',
        to: '2026-10-31',
        unassigned: 1,
    };
    props.actionReturnUrl =
        '/health-safety/corrective-actions?q=Needle&tab=critical_open';
    render(<Reviews {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Matching actions' }));
    expect(screen.getByText('Action due date')).toBeInTheDocument();
    expect(screen.getByText('Only work without an owner')).toBeInTheDocument();
    fireEvent.click(
        screen.getByRole('button', { name: 'Clear matching-action filter' }),
    );
    expect(state.get).toHaveBeenCalledWith(
        '/health-safety/events',
        { ...props.filters, action_context: null },
        expect.anything(),
    );
});

it('retains all action refinements for attention meters and the distinct parent-review link', () => {
    const props = actionProps();
    render(<Actions {...props} />);
    expect(document.querySelectorAll('header.eh-header')).toHaveLength(1);
    expect(
        screen.getByRole('link', { name: 'View reviews in monitoring' }),
    ).toHaveAttribute('href', props.monitoringReviewUrl);
    fireEvent.click(
        screen.getByRole('button', { name: 'View high or critical' }),
    );
    expect(state.get).toHaveBeenCalledWith(
        '/health-safety/corrective-actions',
        { ...props.filters, tab: 'critical_open' },
        { preserveScroll: true },
    );
    fireEvent.click(
        within(
            document.querySelector('header.eh-header') as HTMLElement,
        ).getByRole('button', { name: 'Traceability report' }),
    );
    expect(state.visit).toHaveBeenCalledWith(
        '/health-safety/reports?view=action-traceability',
    );
    fireEvent.change(
        screen.getByRole('searchbox', { name: 'Search corrective actions' }),
        { target: { value: 'changed search' } },
    );
    fireEvent.keyDown(
        screen.getByRole('searchbox', { name: 'Search corrective actions' }),
        { key: 'Enter' },
    );
    expect(state.get).toHaveBeenLastCalledWith(
        '/health-safety/corrective-actions',
        { ...props.filters, q: 'changed search' },
        expect.anything(),
    );
});

it('keeps the review owner, source and workflow flags in readable mobile cards', () => {
    const row: EventRow = {
        id: 10,
        reference_number: 'HS-OWNER',
        event_category: 'incident',
        severity: 'high',
        status: 'investigating',
        occurred_at: '2026-10-07T12:00:00Z',
        reported_at: null,
        site_name: 'Kauri House',
        client_name: 'Synthetic client',
        staff_name: null,
        worksafe_notifiable: true,
        worksafe_status: 'pending',
        worksafe_decision_signed: true,
        handover: {
            status: 'awaiting_acceptance',
            owner: { id: 1, name: 'Named review owner' },
            accepted_by: null,
            accepted_at: null,
        },
        investigation_required: true,
        source: {
            type: 'ClientIncident',
            id: 9,
            label: 'Incident concern',
            url: null,
            unwired: false,
        },
        flags: {
            investigation_overdue: true,
            awaiting_verification: 2,
            worksafe_pending: true,
            unwired: false,
        },
        has_investigation: true,
        has_open_actions: true,
    };
    const onOpen = vi.fn(),
        onRowCtx = vi.fn();
    render(<EventTable rows={[row]} onOpen={onOpen} onRowCtx={onRowCtx} />);
    const cards = within(screen.getByRole('list', { name: 'Safety reviews' }));
    for (const label of [
        'Named review owner',
        'Kauri House',
        'Awaiting acceptance',
        'Investigation overdue',
        '2 waiting to be checked',
        'Open actions',
    ])
        expect(cards.getByText(label)).toBeInTheDocument();
    fireEvent.click(
        cards.getByRole('button', { name: 'Open safety review HS-OWNER' }),
    );
    expect(onOpen).toHaveBeenCalledWith(10);
    fireEvent.click(
        cards.getByRole('button', { name: 'Options for HS-OWNER' }),
    );
    expect(onRowCtx).toHaveBeenCalledWith(expect.anything(), row);
    expect(
        screen.getByRole('region', { name: 'Safety reviews table' }),
    ).toHaveClass('hidden', 'md:block');
    expect(
        within(
            screen.getByRole('region', { name: 'Safety reviews table' }),
        ).getByText('Named review owner'),
    ).toBeInTheDocument();
});

it('keeps existing action work accessible after parent-review closure while blocking new actions there', () => {
    // The register table is the List view; the board (pg-hsn-42) is the default.
    state.url = '/health-safety/corrective-actions?view=list';
    const props = actionProps();
    const row: ActionRow = {
        id: 9,
        reference_number: 'CA-CONTINUE',
        title: 'Continue agreed work',
        action_type: 'corrective',
        priority: 'high',
        status: 'in_progress',
        assigned_to_name: 'Named owner',
        due_date: null,
        is_overdue: false,
        completed_at: null,
        verified_at: null,
        completed_by_user_id: null,
        completed_by_name: null,
        can_verify: false,
        evidence: { load_state: 'loaded', attachments: [] },
        rework: { latest_reason: null },
        recommendation: null,
        source: { type: 'standalone' },
        event: {
            id: 8,
            reference_number: 'HS-CLOSED',
            event_category: 'incident',
            severity: 'high',
            status: 'closed',
            site_name: 'Kauri House',
            url: '/health-safety/events/8',
            monitoring: false,
        },
    };
    props.actions.data = [row];
    render(<Actions {...props} />);
    const table = within(
        screen.getByRole('region', { name: 'Corrective actions table' }),
    );
    expect(
        table.getAllByRole('columnheader').map((cell) => cell.textContent),
    ).toEqual([
        'Due',
        'Action',
        'Stage',
        'Priority',
        'Owner',
        'Parent event',
        'Flags',
        'Actions',
    ]);
    const options = table.getByRole('button', {
        name: 'Lifecycle actions for CA-CONTINUE',
    });
    fireEvent.keyDown(options, { key: 'Enter' });
    expect(state.visit).not.toHaveBeenCalled();
    fireEvent.click(
        within(
            screen.getByRole('list', { name: 'Corrective actions' }),
        ).getByRole('button', { name: 'Lifecycle actions for CA-CONTINUE' }),
    );
    expect(screen.getByText('Mark complete…')).toBeInTheDocument();
    expect(screen.queryByText('Add corrective action')).not.toBeInTheDocument();
});

it('shows actions on a board by stage with real column totals and opens the list lens for the rest', () => {
    const props = actionProps();
    props.tabCounts = {
        ...props.tabCounts,
        open: 4,
        in_progress: 3,
        awaiting_verification: 1,
        unassigned: 1,
        closed: 0,
    };
    props.actions.data = [
        {
            id: 12,
            reference_number: 'CA-BOARD',
            title: 'Fit an alarm to the laundry door',
            action_type: 'corrective',
            priority: 'medium',
            status: 'in_progress',
            assigned_to_name: 'Board owner',
            due_date: '2026-10-30',
            is_overdue: false,
            completed_at: null,
            verified_at: null,
            completed_by_user_id: null,
            completed_by_name: null,
            can_verify: false,
            evidence: { load_state: 'loaded', attachments: [] },
            rework: { latest_reason: null },
            recommendation: null,
            source: { type: 'standalone' },
            event: {
                id: 8,
                reference_number: 'HS-BOARD',
                event_category: 'incident',
                severity: 'medium',
                status: 'corrective_action',
                site_name: 'Kauri House',
                url: '/health-safety/events/8',
                monitoring: false,
            },
        },
    ];
    render(<Actions {...props} />);
    const board = within(
        screen.getByRole('region', { name: 'Corrective actions board' }),
    );
    const progress = within(board.getByRole('region', { name: 'In progress' }));
    expect(
        progress.getByText('Fit an alarm to the laundry door'),
    ).toBeInTheDocument();
    expect(progress.getByText('3')).toBeInTheDocument();
    expect(
        progress.getByRole('button', {
            name: 'Lifecycle actions for CA-BOARD',
        }),
    ).toBeInTheDocument();
    fireEvent.click(progress.getByRole('button', { name: '2 more' }));
    expect(state.get).toHaveBeenCalledWith(
        '/health-safety/corrective-actions',
        { ...props.filters, tab: 'in_progress', view: 'list' },
        { preserveScroll: true },
    );
    expect(
        screen.queryByRole('region', { name: 'Corrective actions table' }),
    ).not.toBeInTheDocument();
});
