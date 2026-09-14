import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import type { AssurancePayload } from '@/components/governance/AssuranceSummaryPanel';
import type { MyWorkPreview } from '@/components/governance/MyNextActionsRail';
import type { NextMeetingPayload } from '@/components/governance/MeetingReadinessPanel';

import { CockpitLayout, type CockpitPayload } from './CockpitLayout';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...rest }: { href: string; children: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    usePage: () => ({ url: '/governance/dashboard', props: { auth: { can: {} } } }),
    router: { visit: vi.fn() },
}));
vi.mock('@/components/governance/PriorityOverviewPanel', () => ({
    PriorityOverviewPanel: ({ summary }: { summary: { total: number } }) => (
        <div data-testid="priority-overview">Priorities Requiring Board Attention ({summary.total})</div>
    ),
}));

const memberPermissions = {
    view: true,
    meetings: { view: true, manage: false },
    risks: { view: true, manage: false },
    compliance: { view: true, manage: false },
    actions: { view: true, manage: false },
    budgets: { view: true, approve: true },
    audit: { view: false },
};

const managerPermissions = {
    ...memberPermissions,
    meetings: { view: true, manage: true },
};

function nextMeeting(overrides: Partial<NextMeetingPayload> = {}): NextMeetingPayload {
    return {
        meeting: {
            id: 7,
            title: 'September Board',
            scheduled_at: '2026-09-17T22:00:00Z',
            scheduled_label: '18 Sep 2026, 10:00 AM',
            days_until: 4,
            status: 'scheduled',
            location: 'Board room',
            chair: 'Chair',
            secretary: 'Secretary',
            href: '/governance/meetings/7',
        },
        progress: { done: 5, total: 8, percent: 63, remaining: 3, blocked: 0 },
        checklist: [
            {
                key: 'ceo_report',
                label: 'CEO report ready',
                status: 'todo',
                detail: 'CEO report is pending for this meeting.',
                action_label: 'Open CEO Report',
                action_url: '/governance/ceo-reports',
                blocked_by: null,
            },
            {
                key: 'minutes_signed',
                label: 'Minutes signed and archived',
                status: 'todo',
                detail: 'Sign approved minutes to close the meeting cycle.',
                action_label: 'Finalize Minutes',
                action_url: '/governance/meetings/7?tab=minutes',
                blocked_by: null,
            },
        ],
        next_step: null,
        member_readiness: {
            workspace_href: '/governance/meetings/7',
            pack: { published: true, read: false, revision_number: 2, href: '/governance/packs/3' },
            papers: { count: 0, href: '/governance/meetings/7?tab=agenda' },
            votes: { available: true, open: 1, href: '/governance/meetings/7?tab=resolutions' },
            conflicts: { is_member: true, declared: 0, decisions_to_check: 1, href: '/governance/meetings/7?tab=resolutions' },
            rsvp: { invited: true, response: null },
        },
        ...overrides,
    };
}

function assurance(overrides: Partial<AssurancePayload> = {}): AssurancePayload {
    return {
        risks_above_appetite: { available: true, count: 12, tracked: 12, href: '/governance/risks?above_appetite=1' },
        obligations_overdue: { available: true, count: 1, href: '/governance/compliance?status=overdue' },
        actions_overdue: { available: true, count: 0, href: '/governance/actions?status=overdue' },
        financial_variance: {
            available: false,
            variance_percent: null,
            material: null,
            threshold_percent: 5,
            href: '/governance/budgets',
        },
        ...overrides,
    };
}

function cockpit(overrides: Partial<CockpitPayload> = {}): CockpitPayload {
    return {
        period_label: 'September 2026',
        sections: [],
        cards: [],
        cards_by_key: {},
        workflow_summary: { total: 58, critical: 13, overdue: 42 },
        role_actions: [],
        kpi_band: [],
        assurance: assurance(),
        next_meeting: nextMeeting(),
        board_pack: null,
        calendar_events: [],
        timeline: { since: null, events: [] },
        recently_completed: [],
        ...overrides,
    };
}

const myWork: MyWorkPreview = {
    items: [
        {
            id: 'resolution:4:vote',
            kind: 'vote',
            source: { type: 'resolution', id: 4, reference: 'RES-4', href: '/governance/resolutions/4' },
            title: 'Vote: Approve budget',
            reason: 'Voting open until 20 Sep 2026, 5:00 PM.',
            priority: 'high',
            status: 'due_soon',
            due_date: '2026-09-20',
            required_action: { key: 'vote', label: 'Cast Vote', href: '/governance/resolutions/4', allowed: true },
        },
    ],
    totals: { all: 12, vote: 1, read: 3, act: 8, know: 0, pending: 12, overdue: 2, blocked: 0, completed: 4 },
    pagination: { total: 12 },
    all_sources_succeeded: true,
    href: '/governance/my-work',
};

const workflow = {
    summary: { total: 58, critical: 13, overdue: 42 },
    actions: [],
    pagination: null,
};

describe('Governance Home composition (CockpitLayout)', () => {
    it('gives an ordinary member Next meeting, My work totals and board-wide priorities without the admin checklist', () => {
        render(
            <CockpitLayout
                cockpit={cockpit()}
                workflow={workflow}
                myWork={myWork}
                permissions={memberPermissions}
            />,
        );

        const nextMeetingCard = screen.getByText('Next meeting').closest('[data-dusk="cockpit-next-meeting"]') as HTMLElement;
        expect(nextMeetingCard).not.toBeNull();
        expect(within(nextMeetingCard).getByRole('link', { name: 'Prepare for meeting' })).toHaveAttribute(
            'href',
            '/governance/meetings/7',
        );
        expect(within(nextMeetingCard).getByText('1 open')).toBeInTheDocument();
        expect(within(nextMeetingCard).getByText('To read')).toBeInTheDocument();

        // Administrative preparation is hidden from ordinary members.
        expect(screen.queryByText('Meeting preparation checklist')).not.toBeInTheDocument();
        expect(screen.queryByText('CEO report ready')).not.toBeInTheDocument();
        expect(screen.queryByText('Minutes signed and archived')).not.toBeInTheDocument();

        // My work uses the full authorised total, not the cards displayed.
        const myWorkCard = screen.getByText('My work').closest('[data-dusk="cockpit-my-next-actions"]') as HTMLElement;
        expect(within(myWorkCard).getByRole('link', { name: /view all 12 in My work/i })).toHaveAttribute(
            'href',
            '/governance/my-work',
        );
        expect(within(myWorkCard).getByRole('link', { name: 'Cast Vote' })).toHaveAttribute(
            'href',
            '/governance/resolutions/4',
        );
        expect(within(myWorkCard).getByText('Due 20 Sep 2026')).toBeInTheDocument();

        // Board priorities are labelled as board-wide.
        expect(screen.getByText('Board priorities')).toBeInTheDocument();
        expect(screen.getByText(/Board-wide · 58 items/)).toBeInTheDocument();
    });

    it('keeps the administrative checklist for meeting managers', () => {
        render(
            <CockpitLayout
                cockpit={cockpit()}
                workflow={workflow}
                myWork={myWork}
                permissions={managerPermissions}
            />,
        );

        expect(screen.getByText('Meeting preparation checklist')).toBeInTheDocument();
        expect(screen.getByText('CEO report ready')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Prepare for meeting' })).toBeInTheDocument();
    });

    it('offers past meetings and records when there is no next meeting', () => {
        render(
            <CockpitLayout
                cockpit={cockpit({ next_meeting: null })}
                workflow={workflow}
                myWork={myWork}
                permissions={memberPermissions}
            />,
        );

        expect(screen.getByText('No upcoming meeting')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'View past meetings' })).toHaveAttribute('href', '/governance/meetings');
        expect(screen.getByRole('link', { name: 'Search records' })).toHaveAttribute('href', '/governance/records');
        expect(screen.queryByRole('link', { name: 'Schedule meeting' })).not.toBeInTheDocument();
    });

    it('never reports "all caught up" when the My work feed failed', () => {
        render(
            <CockpitLayout
                cockpit={cockpit()}
                workflow={workflow}
                myWork={null}
                permissions={memberPermissions}
            />,
        );

        expect(screen.getByText('My work could not be loaded')).toBeInTheDocument();
        expect(screen.queryByText('Nothing is waiting on you')).not.toBeInTheDocument();
    });

    it('states assurance truthfully: risks above appetite are never called within appetite, unavailable is not zero', () => {
        render(
            <CockpitLayout
                cockpit={cockpit()}
                workflow={workflow}
                myWork={myWork}
                permissions={memberPermissions}
            />,
        );

        const headline = screen.getByTestId('assurance-headline');
        expect(headline).toHaveTextContent('12 risks above appetite');
        expect(headline).toHaveTextContent('1 overdue compliance obligation');
        expect(headline).toHaveTextContent('No overdue board actions reported');
        expect(headline).toHaveTextContent('1 source is unavailable');
        expect(headline.textContent).not.toMatch(/within appetite/i);
        expect(headline.textContent).not.toMatch(/no risks above appetite/i);

        const riskTile = screen.getByRole('link', { name: /View risks above appetite/ });
        expect(riskTile).toHaveAttribute('href', '/governance/risks?above_appetite=1');
        expect(riskTile).toHaveTextContent('12 of 12 active risks outside tolerance');

        const financeTile = screen.getByRole('link', { name: /View budget variance/ });
        expect(financeTile).toHaveTextContent('Unavailable');
        expect(financeTile).not.toHaveTextContent('0');
    });

    it('marks unavailable risk data as unavailable rather than none above appetite', () => {
        render(
            <CockpitLayout
                cockpit={cockpit({
                    assurance: assurance({
                        risks_above_appetite: {
                            available: false,
                            count: null,
                            tracked: null,
                            href: '/governance/risks?above_appetite=1',
                        },
                    }),
                })}
                workflow={workflow}
                myWork={myWork}
                permissions={memberPermissions}
            />,
        );

        const riskTile = screen.getByRole('link', { name: /View risks above appetite/ });
        expect(riskTile).toHaveTextContent('Risk register data unavailable');
        expect(riskTile).toHaveTextContent('—');
        expect(screen.getByTestId('assurance-headline').textContent).not.toMatch(/risks above appetite/i);
    });
});
