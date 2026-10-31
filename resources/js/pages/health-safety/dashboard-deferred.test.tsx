import { cleanup, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import HealthSafetyDashboard from './dashboard';

/** Step C: the Overview shows first, and its heavy parts fill in from background requests (Inertia::defer). */
const page = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...props }: { href: string; children: ReactNode }) => <a href={href} {...props}>{children}</a>,
    router: { visit: vi.fn(), get: vi.fn(), reload: vi.fn(), on: () => () => {} },
    usePage: () => ({ url: '/health-safety', props: page.props }),
}));
vi.mock('@/layouts/app-layout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));
afterEach(cleanup);

const firstPaint = {
    source_scope: 'readable_subset' as const,
    source_completeness: 'unknown' as const,
    filters: { from: '2026-10-01', to: '2026-10-31', site: null, lens: 'manager' },
    lens: 'manager',
    sites: [{ id: 1, name: 'Maple House' }, { id: 2, name: 'Rata House' }],
    org_name: 'Demo',
    clients: [],
    staff: [],
    worker_participation: { pct: null, committees: null, source_access: 'unavailable' as const },
    procedures: { approved: 4, review_due: 0, coverage_gap_categories: 0 },
};

const deferred = {
    kpis: { fleet_incidents_30d: 3, fleet_unresolved: 1, open_safeguarding: null, active_alerts: 2, drill_compliance_pct: 80 },
    safeguarding_source: { access: 'restricted' as const, href: null },
    incident_trends: [{ month: '2026-10', count: 5, types: {} }],
    frequency_trends: [{ month: '2026-10', ltifr: null, trifr: null }],
    leading_lagging: {
        lagging: { incidents: 5, ltifr: null, trifr: null, injury_severity_rate: null, days_since_lti: 40 },
        leading: { near_miss_ratio: 3, actions_on_time_pct: 75, training_pct: 90, open_hazards: 6 },
    },
    worklists: {
        attention: [],
        overdue_corrective_actions: [{ id: 10, reference: 'CA-2026-9010', title: 'Install a safety rail', priority: 'high', status: 'in_progress',
            due_date: '2026-10-21', days_overdue: 2, owner: 'Site Manager', client_id: null, staff_id: null, event_reference: 'HS-2026-0080' }],
        open_investigations: [],
        notifiable_events: [],
        expiring: [],
    },
    backbone: { events: { open_events: 1, open_events_high_critical: 0, events_period: 1, worksafe_notifiable_open: 0, worksafe_pending: 0, events_by_severity: {} },
        investigations: { active_investigations: 0, overdue_investigations: 0, awaiting_review: 0 },
        corrective_actions: { open_actions: 1, overdue_actions: 1, awaiting_verification: 0 },
        risk_assessments: { active_assessments: 0, high_extreme_active: 0, due_for_review: 0 },
        training: { total_requirements: 0, blocking_requirements: 0, staff_non_compliant: 0 } },
    exposure_delivery_problems: null,
    site_league: [{ id: 1, name: 'Maple House', incidents: 5, hazards: 2 }],
    incidents_by_category: [],
    frequency_operands: { near_misses: 3, recordable: 1 },
    hazard_burndown: [],
    open_hazards_list: [],
    first_aid: { treatments: 1, ambulance: 0, hospital: 0 },
    restraints: { summary: { events_in_period: 0, out_of_plan: 0, with_injury: 0, critical: 0, unreviewed: 0, active_plans: 0,
        plans_review_due: 0, clients_no_active_bsp: 0, source_access: 'readable' as const }, unreviewed: [] },
};

const shared = { auth: { user: { id: 7 }, can: { governance: { view: false } } } };

describe('Health & safety Overview loads its heavy parts after the page shows', () => {
    it('shows placeholders, never a zero or an empty list, while the background parts load', () => {
        page.props = { ...shared, ...firstPaint };
        render(<HealthSafetyDashboard {...firstPaint} />);

        for (const label of ['headline numbers', 'safety measures', 'what needs you now', 'incident trend', 'site comparison', 'coming due']) {
            expect(screen.getByLabelText(`Loading ${label}`)).toHaveAttribute('aria-busy', 'true');
        }
        const header = screen.getByLabelText('Loading headline numbers');
        expect(within(header).getByText('Near misses per injury')).toBeInTheDocument();
        expect(within(header).getAllByText('Loading')).toHaveLength(4);
        expect(screen.queryByText('0')).not.toBeInTheDocument();
        expect(screen.queryByText(/No overdue corrective actions|Nothing/)).not.toBeInTheDocument();
        expect(screen.queryByText('Install a safety rail')).not.toBeInTheDocument();
    });

    it('fills each panel once its background part arrives', () => {
        page.props = { ...shared, ...firstPaint, ...deferred };
        render(<HealthSafetyDashboard {...firstPaint} {...deferred} />);

        expect(screen.queryByLabelText(/^Loading /)).not.toBeInTheDocument();
        expect(screen.getByText('3×')).toBeInTheDocument();
        expect(screen.getByText('75%')).toBeInTheDocument();
        expect(screen.getByText('Procedures')).toBeInTheDocument();
        expect(screen.getAllByText(/Install a safety rail/).length).toBeGreaterThan(0);
    });
});
