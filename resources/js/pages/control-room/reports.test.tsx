import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ControlRoomReports from './reports';
import ControlRoomStats from './stats';

const inertia = vi.hoisted(() => ({
    visit: vi.fn(),
    get: vi.fn(),
    reload: vi.fn(),
    url: '/control-room/reports',
    breadcrumbs: [] as Array<{ title: string; href?: string }>,
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...props }: { href: string; children: ReactNode }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: inertia,
    usePage: () => ({
        url: inertia.url,
        props: { auth: { can: { controlRoom: { viewAny: true, reportsView: true } } } },
    }),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({
        children,
        breadcrumbs,
    }: {
        children: ReactNode;
        breadcrumbs: Array<{ title: string; href?: string }>;
    }) => {
        inertia.breadcrumbs = breadcrumbs;
        return <div>{children}</div>;
    },
}));

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    inertia.url = '/control-room/reports';
});

const reports = {
    period: '30d',
    site_id: null,
    stats: {
        total_alerts: 186,
        resolved_alerts: 175,
        resolution_rate: 94.1,
        avg_resolution_hours: 0.6,
        escalated_count: 11,
        escalation_rate: 5.9,
    },
    by_severity: { critical: 42, high: 71, medium: 58, low: 15 },
    by_status: { closed: 151, resolved: 24, open: 9, dismissed: 2 },
    by_source: { personal_tracker: 80, sensor: 106 },
    by_alert_type: { fall_detected: 12 },
    daily_trend: [
        { date: '2026-10-20', count: 9 },
        { date: '2026-10-21', count: 4 },
    ],
    response_time_by_severity: {},
    top_assignees: [{ user: 'Jordan Peri', count: 64 }],
    sla: {
        total_with_sla: 20,
        avg_acknowledge_minutes: 3.2,
        avg_response_minutes: 0,
        by_severity: {
            critical: { total: 5, assessed_total: 5, met: 4, breached: 1, in_progress: 0, compliance_pct: 80 },
            high: { total: 0, assessed_total: 0, met: 0, breached: 0, in_progress: 0, compliance_pct: null },
            medium: { total: 0, assessed_total: 0, met: 0, breached: 0, in_progress: 0, compliance_pct: null },
            low: { total: 0, assessed_total: 0, met: 0, breached: 0, in_progress: 0, compliance_pct: null },
        },
    },
    escalation: { escalated: 11, by_level: { 1: 8, 2: 3 }, stuck_at_high_escalation: 0 },
} satisfies ComponentProps<typeof ControlRoomReports>;

const empty = {
    ...reports,
    stats: {
        total_alerts: 0,
        resolved_alerts: 0,
        resolution_rate: 0,
        avg_resolution_hours: 0,
        escalated_count: 0,
        escalation_rate: 0,
    },
    by_severity: {},
    by_status: {},
    by_source: {},
    by_alert_type: {},
    daily_trend: [],
    top_assignees: [],
    sla: { total_with_sla: 0, avg_acknowledge_minutes: 0, avg_response_minutes: 0 },
    escalation: { escalated: 0, by_level: {}, stuck_at_high_escalation: 0 },
} satisfies ComponentProps<typeof ControlRoomReports>;

describe('Reports › Overview', () => {
    it('opens in the Control Room frame with the Reports view tabs', () => {
        render(<ControlRoomReports {...reports} />);

        expect(screen.getByRole('heading', { level: 1, name: 'Reports' })).toBeInTheDocument();
        expect(inertia.breadcrumbs.map((crumb) => crumb.title)).toEqual([
            'Home',
            'Control Room',
            'Reports',
        ]);
        const rail = screen.getByRole('tablist', { name: 'Reports views' });
        for (const tab of ['Overview', 'Trends', 'Target breaches']) {
            expect(within(rail).getByRole('tab', { name: new RegExp(tab) })).toBeInTheDocument();
        }
    });

    it('keeps the export link and its query params', () => {
        inertia.url = '/control-room/reports?period=7d&site_id=4';
        render(<ControlRoomReports {...reports} period="7d" site_id={4} />);
        expect(screen.getByRole('link', { name: 'Export CSV' })).toHaveAttribute(
            'href',
            '/control-room/reports/export?period=7d&site_id=4',
        );
        cleanup();

        inertia.url = '/control-room/reports?date_from=2026-10-01&date_to=2026-10-20';
        render(<ControlRoomReports {...reports} period="custom" />);
        expect(screen.getByRole('link', { name: 'Export CSV' })).toHaveAttribute(
            'href',
            '/control-room/reports/export?date_from=2026-10-01&date_to=2026-10-20',
        );
        // A custom range uses the approved day pickers, never a browser date box.
        expect(screen.getByRole('button', { name: /^From:/ })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /^To:/ })).toBeInTheDocument();
        expect(document.querySelector('input[type="date"]')).toBeNull();
    });

    it('header numbers point at the panel that breaks them down', () => {
        render(<ControlRoomReports {...reports} />);

        fireEvent.click(screen.getByRole('button', { name: 'Show alerts by day' }));
        expect(document.activeElement).toBe(document.getElementById('daily-trend'));
        expect(screen.getByRole('button', { name: 'Show escalations by level' })).toHaveTextContent('5.9%');
    });

    it('says "Not measured" instead of a fake 0 and hides what was never recorded', () => {
        render(<ControlRoomReports {...empty} />);

        const resolution = screen.getByRole('button', { name: 'Show alerts by status' });
        expect(resolution).toHaveTextContent('Not measured');
        expect(resolution).not.toHaveTextContent('0%');
        expect(screen.getByRole('button', { name: 'Show response times' })).toHaveTextContent(
            'Not measured',
        );
        // Nothing raised in the period: one message instead of a page of empty panels.
        expect(screen.getByText('No alerts in this period')).toBeInTheDocument();
        expect(screen.queryByText('No response targets ran in this period')).not.toBeInTheDocument();
        expect(screen.queryByText('Average time to acknowledge')).not.toBeInTheDocument();
    });

    it('response times list only severities that had a target, without an always-empty column', () => {
        render(<ControlRoomReports {...reports} />);

        const panel = within(document.getElementById('response-times') as HTMLElement);
        expect(panel.getByRole('rowheader', { name: 'Critical' })).toBeInTheDocument();
        expect(panel.queryByRole('rowheader', { name: 'High' })).not.toBeInTheDocument();
        expect(panel.queryByRole('columnheader', { name: 'Average response' })).not.toBeInTheDocument();
        expect(panel.getByText('80%')).toBeInTheDocument();
        expect(panel.getByText('3 min')).toBeInTheDocument();
        // avg_response_minutes 0 means nothing was timed, so it is left out.
        expect(panel.queryByText('Average time to respond')).not.toBeInTheDocument();
    });
});

const stats = {
    period: '7d',
    kpis: {
        avg_acknowledge_minutes: 0,
        avg_resolution_hours: 0,
        sla_compliance_pct: null,
        open_alerts: 9,
        alerts_today: 31,
    },
    volume_trend: [
        { label: 'Oct 20', count: 3 },
        { label: 'Oct 21', count: 5 },
    ],
    top_sources: [{ name: 'personal_tracker', count: 8 }],
    top_alert_types: [],
    severity_distribution: { critical: 2, high: 4 },
    operator_performance: [{ name: 'Jordan Peri', alerts_handled: 12, avg_response_minutes: 0 }],
    shift_comparison: [],
    signal_sources: [],
} satisfies ComponentProps<typeof ControlRoomStats>;

describe('Reports › Trends', () => {
    it('opens on the Trends tab with linked numbers and never a fake 0', () => {
        inertia.url = '/control-room/stats';
        render(<ControlRoomStats {...stats} />);

        expect(screen.getByRole('heading', { level: 1, name: 'Reports' })).toBeInTheDocument();
        const rail = screen.getByRole('tablist', { name: 'Reports views' });
        expect(within(rail).getByRole('tab', { name: /Trends/ })).toHaveAttribute(
            'aria-selected',
            'true',
        );
        expect(screen.getByRole('link', { name: 'Show the alerts that need action' })).toHaveAttribute(
            'href',
            '/control-room',
        );
        const compliance = screen.getByRole('link', { name: 'Show missed response targets' });
        expect(compliance).toHaveAttribute('href', '/control-room/sla/breaches');
        expect(compliance).toHaveTextContent('Not measured');
        expect(screen.getByRole('button', { name: 'Show response speed by operator' })).toHaveTextContent(
            'Not measured',
        );
        // An acknowledge column with no measured value is left out.
        expect(
            screen.queryByRole('columnheader', { name: 'Average time to acknowledge' }),
        ).not.toBeInTheDocument();
    });

    it('reads trend labels the NZ way in the chart data', () => {
        inertia.url = '/control-room/stats';
        render(<ControlRoomStats {...stats} />);

        // The exact values sit under "View chart data" (a closed <details>).
        const volume = within(document.getElementById('volume-trend') as HTMLElement);
        expect(
            volume.getByRole('rowheader', { name: '20 Oct', hidden: true }),
        ).toBeInTheDocument();
        expect(screen.getAllByText('Personal tracker').length).toBeGreaterThan(0);
    });

    it('stays honest when the server sends no figures at all', () => {
        inertia.url = '/control-room/stats';
        render(<ControlRoomStats period="7d" kpis={null} />);

        expect(screen.getByRole('link', { name: 'Show the alerts that need action' })).toHaveTextContent(
            'Not measured',
        );
        expect(screen.getAllByText('No alerts in this period').length).toBeGreaterThan(0);
    });
});
