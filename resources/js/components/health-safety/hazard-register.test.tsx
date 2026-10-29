import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';

const state = vi.hoisted(() => ({
    get: vi.fn(),
    visit: vi.fn(),
    reports: true,
}));
vi.mock('@inertiajs/react', () => ({
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
        props: {
            auth: {
                can: {
                    hazards: { view: true },
                    procedures: { view: true },
                    governance: { view: state.reports },
                    sites: { viewAny: false },
                },
            },
        },
    }),
    router: { get: state.get, visit: state.visit },
}));
vi.mock('./hazard-create-dialog', () => ({ HazardCreateDialog: () => null }));
vi.mock('./hazard-detail-dialog', () => ({ HazardDetailDialog: () => null }));

import {
    RiskChip,
    SeverityChip,
    StatusChip,
    WORKSAFE_BANNER,
    type HazardRow,
} from './hazard-kit';
import {
    HazardRegister,
    HazardTable,
    type HazardRegisterData,
} from './hazard-register';

function row(): HazardRow {
    return {
        id: 1,
        reference_number: 'HZ-SYNTHETIC',
        site_id: 1,
        site_name: 'Kauri House',
        site_type: 'house',
        hazard_type: 'slip_trip_fall',
        hazard_label: 'Slip / trip / fall',
        severity: 'high',
        likelihood: 'likely',
        risk_rating: 'extreme',
        description: 'Synthetic loose carpet by the doorway',
        status: 'open',
        assigned_to_id: 9,
        assigned_to_name: 'Named hazard owner',
        reported_by_name: 'Synthetic reporter',
        due_date: '2026-10-08',
        created_at: '2026-10-07T11:30:00.000000Z',
        worksafe: true,
        open_action_count: 2,
        flags: {
            overdue: false,
            due_soon: true,
            unassigned: false,
            awaiting_closure: false,
        },
    };
}

function data(): HazardRegisterData {
    return {
        hazards: {
            data: [row()],
            links: [],
            last_page: 1,
            total: 1,
            from: 1,
            to: 1,
        },
        tab: 'all',
        tabCounts: {
            all: 8,
            open: 2,
            in_progress: 2,
            overdue: 1,
            critical: 1,
            closed: 4,
            due_soon: 2,
            unassigned: 1,
            mitigated: 3,
            closed_only: 1,
        },
        hero: {
            live: { open: 2, in_progress: 2, overdue: 1, critical: 1 },
            attention: {
                due_soon: 2,
                unassigned: 1,
                mitigated: 3,
                closed_period: 1,
            },
        },
        filters: {
            q: 'Needle',
            site_id: 1,
            site_type: null,
            severity: 'high',
            risk_rating: null,
            assignee_id: null,
            due_state: null,
            tab: 'all',
            from: '2026-10-01',
            to: '2026-10-08',
        },
        sites: [{ id: 1, name: 'Kauri House', type: 'house' }],
        assignees: [{ id: 9, name: 'Named hazard owner' }],
        detail: null,
        can: { manage: false, assign: false, close: false, create: false },
        severityOptions: ['low', 'medium', 'high', 'critical'],
        likelihoodOptions: ['rare', 'likely'],
        riskRatings: ['low', 'medium', 'high', 'extreme'],
        recommendedBySiteType: {},
    };
}

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    state.reports = true;
});

it('uses the shared header and exact filtered attention views without the old ribbon or global assurance badges', () => {
    const props = data();
    const { container } = render(
        <HazardRegister baseUrl="/compliance/hazards" data={props} />,
    );
    const header = container.querySelector('header.eh-header')! as HTMLElement;
    expect(container.querySelectorAll('header.eh-header')).toHaveLength(1);
    const headerQueries = within(header);
    expect(
        headerQueries.getByRole('heading', { name: 'Hazards' }),
    ).toBeInTheDocument();
    expect(
        headerQueries.getByRole('tab', { name: 'Risk assessments' }),
    ).toBeInTheDocument();
    expect(screen.queryByLabelText('Safety workflow')).not.toBeInTheDocument();
    expect(
        screen.queryByText(/Ngā Paerewa|SDS expiring|WorkSafe awaiting/),
    ).not.toBeInTheDocument();
    expect(
        headerQueries.queryByRole('button', { name: 'Log hazard' }),
    ).not.toBeInTheDocument();
    fireEvent.click(
        headerQueries.getByRole('button', { name: 'View due in 7 days' }),
    );
    expect(state.get).toHaveBeenLastCalledWith(
        '/compliance/hazards',
        { ...props.filters, tab: 'due_soon' },
        expect.anything(),
    );
    fireEvent.click(
        headerQueries.getByRole('button', { name: 'View needs an owner' }),
    );
    expect(state.get).toHaveBeenLastCalledWith(
        '/compliance/hazards',
        { ...props.filters, tab: 'unassigned' },
        expect.anything(),
    );
    // Only the two drawn numbers sit in the header; the rest are views.
    expect(
        headerQueries.queryByRole('button', { name: 'View open' }),
    ).not.toBeInTheDocument();
    const views = within(screen.getByRole('tablist', { name: 'Hazard views' }));
    expect(
        views.getAllByRole('tab').map((tab) => tab.textContent),
    ).toEqual(['All8', 'Open', 'Overdue1', 'Critical1', 'Closed & mitigated4']);
    fireEvent.click(views.getByRole('tab', { name: /Overdue/ }));
    expect(state.get).toHaveBeenLastCalledWith(
        '/compliance/hazards',
        { ...props.filters, tab: 'overdue' },
        expect.anything(),
    );
    expect(
        headerQueries.getByRole('button', { name: /More filters · 1/ }),
    ).toBeInTheDocument();
});

it('keeps a chosen view outside the drawn strip visible as its own tab', () => {
    const props = data();
    props.tab = 'mitigated';
    render(<HazardRegister baseUrl="/compliance/hazards" data={props} />);
    const views = within(screen.getByRole('tablist', { name: 'Hazard views' }));
    expect(
        views.getByRole('tab', { name: /Awaiting closure/, selected: true }),
    ).toBeInTheDocument();
    expect(
        screen.getByRole('tabpanel', { name: 'Awaiting closure' }),
    ).toBeInTheDocument();
});

it('retains Site and period in exports and uses readable Site-scoped report pages with report permission', () => {
    const props = data();
    props.tab = 'due_soon';
    props.filters.tab = 'due_soon';
    const { container, rerender } = render(
        <HazardRegister
            baseUrl="/sites/1/hazards"
            scopedSite={{ id: 1, name: 'Kauri House', type: 'house' }}
            data={props}
        />,
    );
    const header = within(
        container.querySelector('header.eh-header')! as HTMLElement,
    );
    const target = new URL(
        header.getByRole('link', { name: 'Export CSV' }).getAttribute('href')!,
        'https://app.example',
    );
    expect(target.pathname).toBe('/sites/1/hazards/export');
    expect(target.searchParams.get('tab')).toBe('due_soon');
    expect(target.searchParams.get('q')).toBe('Needle');
    expect(target.searchParams.get('from')).toBe('2026-10-01');
    fireEvent.click(header.getByRole('button', { name: 'Board reports' }));
    fireEvent.click(screen.getByRole('button', { name: 'WorkSafe notifications' }));
    expect(state.visit).toHaveBeenLastCalledWith(
        '/health-safety/reports?view=notifications&site_id=1',
    );
    state.reports = false;
    rerender(
        <HazardRegister
            baseUrl="/sites/1/hazards"
            scopedSite={{ id: 1, name: 'Kauri House', type: 'house' }}
            data={props}
        />,
    );
    expect(
        header.queryByRole('button', { name: 'Board reports' }),
    ).not.toBeInTheDocument();
});

it('shows owner, due date and flags in the table, with one action list on the kebab and right-click, and no automatic notification decision', () => {
    const onOpen = vi.fn();
    const view = vi.fn();
    const actionsFor = vi.fn(() => [{ label: 'View hazard', onClick: view }]);
    render(
        <HazardTable
            rows={[row()]}
            scoped={false}
            onOpen={onOpen}
            actionsFor={actionsFor}
        />,
    );
    const table = within(screen.getByRole('region', { name: 'Hazards table' }));
    for (const label of ['Kauri House', 'Rated critical', 'Due within 7 days'])
        expect(table.getByText(label)).toBeInTheDocument();
    expect(
        table.getByText(
            /Synthetic loose carpet by the doorway · Owner: Named hazard owner/,
        ),
    ).toBeInTheDocument();
    expect(table.getByText(/HZ-SYNTHETIC · Due 8 Oct 2026/)).toBeInTheDocument();
    expect(table.getByText('8 Oct 2026')).toBeInTheDocument();
    expect(table.getByText('2 open actions')).toBeInTheDocument();
    expect(table.queryByText(/WorkSafe-notifiable/)).not.toBeInTheDocument();
    fireEvent.click(table.getByText('Kauri House'));
    expect(onOpen).toHaveBeenCalledWith(1);
    fireEvent.contextMenu(table.getAllByRole('row')[1]);
    fireEvent.click(screen.getByRole('menuitem', { name: 'View hazard' }));
    expect(view).toHaveBeenCalledTimes(1);
    expect(actionsFor).toHaveBeenCalledWith(row());
    expect(
        table.getByRole('button', {
            name: 'Actions for HZ-SYNTHETIC · Slip / trip / fall',
        }),
    ).toBeInTheDocument();
});

it('does not turn missing severity risk or status into a reassuring known state', () => {
    render(
        <>
            <SeverityChip severity="unknown" />
            <RiskChip rating="" />
            <StatusChip status="unknown" />
        </>,
    );
    expect(screen.getByText('Severity unknown')).toBeInTheDocument();
    expect(screen.getByText('Risk not assessed')).toBeInTheDocument();
    expect(screen.getByText('Status unknown')).toBeInTheDocument();
    expect(WORKSAFE_BANNER).not.toContain('meets the threshold');
    expect(WORKSAFE_BANNER).toContain('record the decision');
});
