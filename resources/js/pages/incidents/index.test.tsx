import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { type ComponentProps, type ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

const state = vi.hoisted(() => ({ get: vi.fn(), visit: vi.fn(), actorId: 7 }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    usePage: () => ({
        url: '/incidents?q=fixture&site_id=3',
        props: {
            auth: {
                user: { id: state.actorId },
                can: {
                    incidents: { viewAny: true },
                    safeguarding: { viewAny: true },
                    fleet: { viewAny: true },
                },
            },
        },
    }),
    router: { get: state.get, visit: state.visit },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
vi.mock('@/components/incidents/incident-detail-dialog', () => ({
    IncidentDetailDialog: () => null,
}));
vi.mock('@/components/incidents/incident-report-dialog', () => ({
    IncidentReportDialog: ({ mode }: { mode: string }) => (
        <div role="dialog" aria-label={`Report ${mode}`} />
    ),
}));

import { SafetyDateFilter } from '@/components/health-safety/workspace-header';
import IncidentsIndex, {
    FollowupTable,
    IncidentTable,
    type IncidentRow,
} from './index';

function props(): ComponentProps<typeof IncidentsIndex> {
    return {
        viewer_id: 7,
        filters: {
            owner: 'all',
            q: 'fixture',
            tab: 'all',
            severity: 'high',
            client_id: null,
            site_id: 3,
            source: 'manual',
            from: null,
            to: null,
        },
        tab: 'all',
        tabCounts: { all: 3, open: 2, worksafe_pending: 1 },
        rows: { data: [], links: [], last_page: 1 },
        rowsKind: 'incidents',
        hero: {
            period: {
                reported: { value: 3, delta: 1 },
                open: { value: 2 },
                investigation: { value: 1 },
                closed: { value: 1, delta: 0 },
            },
            attention: {
                followups: { value: 2, overdue: 1 },
                review: { value: 1 },
                worksafe: { value: 1 },
                alerts: { value: 1 },
            },
        },
        nearMissInsights: { trend_pct: null, ratio: null, by_potential: {} },
        sites: [],
        clients: [],
        reportClients: [],
        reportStaff: [],
        can: { create: true, templatesManage: false, reportSafeguarding: true },
        detail: null,
    };
}
afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    state.actorId = 7;
});

describe('incident register header', () => {
    it('changes the owner lens while preserving the existing Site, severity and search filters', () => {
        const data = props();
        render(<IncidentsIndex {...data} />);
        fireEvent.click(screen.getByRole('button', { name: 'Incident owner' }));
        fireEvent.click(screen.getByRole('button', { name: 'My incidents' }));
        expect(state.get).toHaveBeenCalledWith(
            '/incidents',
            { ...data.filters, owner: 'me' },
            expect.any(Object),
        );
    });

    it('conceals stale register information and reporting when the viewer does not match the current account', () => {
        const data = props();
        data.viewer_id = 99;
        data.report = 'near_miss';
        render(<IncidentsIndex {...data} />);
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Reload to check current access',
        );
        expect(
            screen.queryByRole('searchbox', { name: 'Search incidents' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Report' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('dialog', { name: 'Report near_miss' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Reload incidents' }),
        );
        expect(state.get).toHaveBeenCalledWith(
            '/incidents',
            {},
            { preserveState: false },
        );
    });

    it('resets the prior account reporting state when a fresh register for another account arrives', () => {
        const view = render(<IncidentsIndex {...props()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Report' }));
        fireEvent.click(screen.getByRole('button', { name: /A near miss/ }));
        expect(
            screen.getByRole('dialog', { name: 'Report near_miss' }),
        ).toBeInTheDocument();
        state.actorId = 9;
        view.rerender(<IncidentsIndex {...props()} viewer_id={9} />);
        expect(
            screen.queryByRole('dialog', { name: 'Report near_miss' }),
        ).not.toBeInTheDocument();
    });
    it('does not turn an unavailable Control Room count into an empty queue claim', () => {
        const data = props();
        data.hero.attention.alerts.value = null;
        render(<IncidentsIndex {...data} />);
        expect(
            screen.queryByText(/linked to active Control Room/),
        ).not.toBeInTheDocument();
    });
    it('uses one header with permitted sibling destinations and no workflow ribbon', () => {
        render(<IncidentsIndex {...props()} />);
        expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
        expect(
            screen.queryByLabelText('Safety workflow'),
        ).not.toBeInTheDocument();
        const rail = screen.getByRole('tablist', {
            name: 'Incidents & concerns views',
        });
        expect(within(rail).getAllByRole('tab')).toHaveLength(3);
        fireEvent.click(
            within(rail).getByRole('tab', {
                name: 'Safeguarding',
            }),
        );
        expect(state.visit).toHaveBeenCalledWith('/safeguarding');
    });

    it.each([
        ['View occurred · 30 days', 'reported_recent'],
        ['View closed · 30 days', 'closed_recent'],
        ['View worksafe pending', 'worksafe_pending'],
    ])('opens the exact %s worklist while retaining filters', (name, tab) => {
        const data = props();
        render(<IncidentsIndex {...data} />);
        fireEvent.click(screen.getByRole('button', { name }));
        expect(state.get).toHaveBeenCalledWith(
            '/incidents',
            { ...data.filters, tab },
            { preserveScroll: true },
        );
    });

    it('searches within the existing site, source and severity scope', () => {
        const data = props();
        render(<IncidentsIndex {...data} />);
        const search = screen.getByRole('searchbox', {
            name: 'Search incidents',
        });
        fireEvent.change(search, { target: { value: '  follow-up  ' } });
        fireEvent.keyDown(search, { key: 'Enter' });
        expect(state.get).toHaveBeenCalledWith(
            '/incidents',
            { ...data.filters, q: 'follow-up' },
            expect.any(Object),
        );
    });

    it('keeps near-miss reporting and adds the independently permitted safeguarding choice', () => {
        render(<IncidentsIndex {...props()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Report' }));
        expect(
            screen.getByRole('button', { name: /A safeguarding concern/ }),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(/takes under a minute/),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /A near miss/ }));
        expect(
            screen.getByRole('dialog', { name: 'Report near_miss' }),
        ).toBeInTheDocument();
    });

    it('does not offer ungranted reporting or templates', () => {
        render(
            <IncidentsIndex
                {...props()}
                can={{
                    create: false,
                    templatesManage: false,
                    reportSafeguarding: false,
                }}
            />,
        );
        expect(
            screen.queryByRole('button', { name: 'Report' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Templates' }),
        ).not.toBeInTheDocument();
    });

    it('keeps the incident register context when starting safeguarding reporting', () => {
        render(<IncidentsIndex {...props()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Report' }));
        fireEvent.click(
            screen.getByRole('button', { name: /A safeguarding concern/ }),
        );
        const target = new URL(
            state.visit.mock.calls[0][0],
            'https://example.test',
        );
        expect(target.pathname).toBe('/safeguarding/create');
        expect(target.searchParams.get('return_to')).toBe(
            '/incidents?q=fixture&site_id=3',
        );
    });

    it('validates a custom NZ date range and retains open-ended periods', () => {
        const onChange = vi.fn();
        render(<SafetyDateFilter from={null} to={null} onChange={onChange} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Period: all dates' }),
        );
        fireEvent.change(screen.getByLabelText('From (NZ date)'), {
            target: { value: '2026-10-09' },
        });
        fireEvent.change(screen.getByLabelText('To (NZ date)'), {
            target: { value: '2026-10-08' },
        });
        expect(screen.getByRole('alert')).toHaveTextContent(
            'end date must be on or after',
        );
        expect(
            screen.getByRole('button', { name: 'Apply range' }),
        ).toBeDisabled();
        fireEvent.change(screen.getByLabelText('To (NZ date)'), {
            target: { value: '' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Apply range' }));
        expect(onChange).toHaveBeenCalledWith({ from: '2026-10-09', to: null });
    });
});

describe('incident mobile worklists', () => {
    it('retains status, severity, ownership, source and flags beside accessible record/menu controls', () => {
        const incident: IncidentRow = {
            id: 4,
            ref: 'INC-4',
            occurred_at: '2026-10-07T01:00:00Z',
            type: 'near_miss',
            description: 'A long account of what could have happened.',
            severity: 'low',
            potential_severity: 'high',
            status: 'submitted',
            source: 'sensor',
            interactive: true,
            worksafe_notification_status: 'pending',
            investigation_status: null,
            control_room_alert_id: null,
            requires_followup: true,
            is_notifiable: true,
            attachments_count: 2,
            open_followups_count: 1,
            client: {
                id: 3,
                first_name: 'Aroha',
                last_name: 'Whānau',
                site: 'Kauri House',
            },
            reporter: { name: 'Sam' },
        };
        const onOpen = vi.fn();
        const onRowCtx = vi.fn();
        render(
            <IncidentTable
                rows={[incident]}
                onOpen={onOpen}
                onRowCtx={onRowCtx}
            />,
        );
        const cards = screen.getByRole('list', { name: 'Incidents' });
        expect(cards).toHaveClass('md:hidden');
        expect(
            screen.getByRole('region', { name: 'Incidents table' }),
        ).toHaveClass('hidden', 'md:block', 'overflow-x-auto');
        for (const label of [
            'Potential: High',
            'Owner not recorded',
            'Sensor',
            'WorkSafe-notifiable',
            '1 open follow-ups',
            '2 attachments',
        ])
            expect(within(cards).getByText(label)).toBeInTheDocument();
        fireEvent.click(
            within(cards).getByRole('button', { name: 'Open incident INC-4' }),
        );
        expect(onOpen).toHaveBeenCalledWith(4);
        fireEvent.click(
            within(cards).getByRole('button', { name: 'Options for INC-4' }),
        );
        expect(onRowCtx).toHaveBeenCalledWith(expect.anything(), incident);
    });

    it('does not claim that all follow-ups are complete when the filtered worklist is empty', () => {
        render(<FollowupTable rows={[]} onOpen={vi.fn()} />);
        expect(
            screen.getByText(
                'No open follow-ups match this view and its filters.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(/Every follow-up.*complete/),
        ).not.toBeInTheDocument();
    });
});
