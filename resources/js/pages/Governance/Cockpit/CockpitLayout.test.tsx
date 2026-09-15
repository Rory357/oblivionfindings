import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import {
    assuranceAttention,
    assuranceExtras,
    assuranceVisibility,
    type AssurancePayload,
} from '@/components/governance/AssuranceSummaryPanel';
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
    PriorityOverviewPanel: ({
        summary,
        collapsedCount,
        showTabs,
    }: {
        summary: { total: number };
        collapsedCount?: number;
        showTabs?: boolean;
    }) => (
        <div
            data-testid="priority-overview"
            data-collapsed={collapsedCount}
            data-tabs={String(showTabs)}
        >
            {summary.total} ranked
        </div>
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
    audit: { view: true },
};

function nextMeeting(overrides: Partial<NextMeetingPayload> = {}): NextMeetingPayload {
    return {
        meeting: {
            id: 7,
            title: 'September Board',
            scheduled_at: '2026-09-17T22:00:00Z',
            scheduled_label: '18 Sep 2026, 10:00 am',
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
                detail: "The CEO report hasn't been submitted yet.",
                action_label: 'Open CEO report',
                action_url: '/governance/ceo-reports',
                blocked_by: null,
            },
            {
                key: 'minutes_signed',
                label: 'Minutes signed',
                status: 'blocked',
                detail: 'Sign the approved minutes to finish this meeting.',
                action_label: 'Finalise minutes',
                action_url: '/governance/meetings/7?tab=minutes',
                blocked_by: "The minutes haven't been approved",
            },
        ],
        next_step: null,
        member_readiness: {
            workspace_href: '/governance/meetings/7',
            pack: { published: true, read: false, revision_number: 2, href: '/governance/packs/3' },
            agenda: { count: 4, href: '/governance/meetings/7?tab=agenda' },
            papers: { count: 2, href: '/governance/meetings/7?tab=resolutions' },
            votes: { available: true, open: 1, href: '/governance/meetings/7?tab=resolutions' },
            conflicts: { is_member: true, declared: 0, decisions_to_check: 1, href: '/governance/meetings/7?tab=resolutions' },
            rsvp: { invited: true, response: null },
        },
        ...overrides,
    };
}

function assurance(overrides: Partial<AssurancePayload> = {}): AssurancePayload {
    return {
        risks_above_appetite: { permitted: true, available: true, count: 12, tracked: 12, href: '/governance/risks?above_appetite=1' },
        obligations_overdue: { permitted: true, available: true, count: 1, href: '/governance/compliance?status=overdue' },
        actions_overdue: { permitted: true, available: true, count: 0, href: '/governance/actions?status=overdue' },
        financial_variance: {
            permitted: true,
            available: false,
            variance_percent: null,
            material: null,
            threshold_percent: 5,
            href: '/governance/budgets',
        },
        ...overrides,
    };
}

function card(key: string, title: string, status: string) {
    return {
        key,
        title,
        description: `${title} description`,
        status,
        source: 'Register',
        freshness: { status: 'fresh', at: null, label: 'Updated just now' },
        metrics: [
            { label: 'Breaches still open', value: '1', tone: 'critical' },
            { label: 'Breaches (last 90 days)', value: '2', tone: 'warning' },
        ],
        highlights: [],
        href: `/${key}`,
    };
}

function cockpit(overrides: Partial<CockpitPayload> = {}): CockpitPayload {
    return {
        period_label: 'This month',
        sections: [],
        cards: [],
        cards_by_key: {},
        workflow_summary: { total: 58, critical: 13, overdue: 42 },
        role_actions: [],
        assurance: assurance(),
        next_meeting: nextMeeting(),
        timeline: { since: null, events: [] },
        recently_completed: [
            {
                kind: 'policy_approved',
                title: 'Complaints policy',
                reference: 'POL-7',
                completed_at: '2026-09-10T00:00:00Z',
                completed_label: '4 days ago',
                href: '/governance/policies/7',
                owner: null,
            },
        ],
        ...overrides,
    };
}

const myWork: MyWorkPreview = {
    items: [
        {
            id: 'resolution:4:vote',
            kind: 'vote',
            source: { type: 'resolution', id: 4, reference: 'RES-4', href: '/governance/resolutions/4' },
            title: 'Approve the 2026/27 budget',
            reason: 'Voting closes in 6 days — 20 Sep 2026, 5:00 pm.',
            priority: 'high',
            status: 'due_soon',
            due_date: '2026-09-20',
            required_action: { key: 'vote', label: 'Vote', href: '/governance/meetings/7?tab=resolutions&paper=4', allowed: true },
        },
    ],
    coming_up: [],
    totals: { all: 12, vote: 1, read: 3, act: 8, know: 2, pending: 12, overdue: 2, blocked: 0, completed: 4 },
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
    it('gives an ordinary member Next meeting, My work, one assurance summary and the top 3 priorities — nothing else', () => {
        render(
            <CockpitLayout
                cockpit={cockpit()}
                workflow={workflow}
                myWork={myWork}
                permissions={memberPermissions}
            />,
        );

        const nextMeetingCard = screen.getByText('Next meeting').closest('[data-dusk="cockpit-next-meeting"]') as HTMLElement;
        expect(within(nextMeetingCard).getByRole('link', { name: 'Prepare for meeting' })).toHaveAttribute(
            'href',
            '/governance/meetings/7',
        );
        expect(within(nextMeetingCard).getByText('1 open')).toBeInTheDocument();
        expect(within(nextMeetingCard).getByText('To read')).toBeInTheDocument();
        // Agenda and decision papers are separate rows with honest counts.
        expect(within(nextMeetingCard).getByText('4 items')).toBeInTheDocument();
        expect(within(nextMeetingCard).getByText('2 papers')).toBeInTheDocument();
        expect(within(nextMeetingCard).getByRole('link', { name: 'Reply' })).toHaveAttribute(
            'href',
            '/governance/meetings/7?tab=attendance',
        );

        // Administrative preparation is hidden from ordinary members.
        expect(screen.queryByText('Meeting preparation checklist')).not.toBeInTheDocument();
        expect(screen.queryByText('CEO report ready')).not.toBeInTheDocument();

        // My work uses the full authorised total, the shared kind names and server wording.
        const myWorkCard = screen.getByText('My work').closest('[data-dusk="cockpit-my-next-actions"]') as HTMLElement;
        expect(within(myWorkCard).getByRole('link', { name: 'See all 12 in My work' })).toHaveAttribute(
            'href',
            '/governance/my-work',
        );
        expect(within(myWorkCard).getByRole('link', { name: 'Vote' })).toHaveAttribute(
            'href',
            '/governance/meetings/7?tab=resolutions&paper=4',
        );
        expect(within(myWorkCard).getByText('Due 20 Sep 2026')).toBeInTheDocument();
        expect(within(myWorkCard).getByText('Ref RES-4')).toBeInTheDocument();

        // Board priorities: one heading, top 3, no area tabs.
        expect(screen.getAllByText('Board priorities')).toHaveLength(1);
        const priorities = screen.getByTestId('priority-overview');
        expect(priorities).toHaveAttribute('data-collapsed', '3');
        expect(priorities).toHaveAttribute('data-tabs', 'false');

        // No operational signals, timeline or recently completed for members.
        expect(screen.queryByText('Service, safety and people')).not.toBeInTheDocument();
        expect(screen.queryByText('Recently completed')).not.toBeInTheDocument();
        expect(screen.queryByText("What's changed since the last meeting")).not.toBeInTheDocument();
    });

    it('keeps the checklist, area tabs, other areas, timeline and signals for meeting managers', () => {
        render(
            <CockpitLayout
                cockpit={cockpit({
                    cards_by_key: {
                        privacy_data: card('privacy_data', 'Privacy and personal information', 'critical'),
                        client_safety: card('client_safety', 'Safety of the people we support', 'good'),
                    },
                })}
                workflow={workflow}
                myWork={myWork}
                permissions={managerPermissions}
            />,
        );

        expect(screen.getByText('Meeting preparation checklist')).toBeInTheDocument();
        expect(screen.getByText('CEO report ready')).toBeInTheDocument();
        expect(screen.getByText("Waiting on: The minutes haven't been approved")).toBeInTheDocument();
        expect(screen.getByTestId('priority-overview')).toHaveAttribute('data-tabs', 'true');
        expect(screen.getByTestId('priority-overview')).toHaveAttribute('data-collapsed', '8');
        expect(screen.getByText('Service, safety and people')).toBeInTheDocument();
        expect(screen.getByText("What's changed since the last meeting")).toBeInTheDocument();
        expect(screen.getByText('Recently completed')).toBeInTheDocument();
        expect(screen.getByText('Policy approved')).toBeInTheDocument();

        // The privacy card is a compact row in Board assurance, not a separate panel.
        const extra = document.querySelector('[data-dusk="assurance-extra-privacy_data"]') as HTMLElement;
        expect(extra).toHaveAttribute('href', '/privacy_data');
        expect(extra).toHaveTextContent('Breaches still open: 1');
        // …and its alert is part of the headline, so assurance never reads "all clear" beside it.
        expect(screen.getByTestId('assurance-headline')).toHaveTextContent(
            'Privacy and personal information (needs action)',
        );
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

    it('never says there is nothing to do when the My work feed failed', () => {
        render(
            <CockpitLayout
                cockpit={cockpit()}
                workflow={workflow}
                myWork={null}
                permissions={memberPermissions}
            />,
        );

        expect(screen.getByText("My work couldn't be loaded")).toBeInTheDocument();
        expect(screen.queryByText('Nothing to do right now')).not.toBeInTheDocument();
    });

    it('states assurance truthfully in plain words: flagged areas, clear areas and areas not available', () => {
        render(
            <CockpitLayout
                cockpit={cockpit()}
                workflow={workflow}
                myWork={myWork}
                permissions={memberPermissions}
            />,
        );

        const headline = screen.getByTestId('assurance-headline');
        expect(headline).toHaveTextContent("12 risks above the board's limit");
        expect(headline).toHaveTextContent('1 overdue requirement');
        expect(headline).toHaveTextContent('No overdue board actions reported');
        expect(headline).toHaveTextContent('1 area is not available');
        expect(headline.textContent).not.toMatch(/appetite|tolerance|obligation|variance/i);

        const riskTile = screen.getByRole('link', { name: /View risks above the board's limit/ });
        expect(riskTile).toHaveAttribute('href', '/governance/risks?above_appetite=1');
        expect(riskTile).toHaveTextContent('12 of 12 open risks');
        expect(screen.getByRole('button', { name: "What does the board’s limit mean?" })).toBeInTheDocument();

        const financeTile = screen.getByRole('link', { name: /View spending against budget/ });
        expect(financeTile).toHaveTextContent('Not available');
        expect(financeTile).not.toHaveTextContent('0');
    });

    it('marks unavailable risk data as not available rather than none above the limit', () => {
        render(
            <CockpitLayout
                cockpit={cockpit({
                    assurance: assurance({
                        risks_above_appetite: {
                            permitted: true,
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

        const riskTile = screen.getByRole('link', { name: /View risks above the board's limit/ });
        expect(riskTile).toHaveTextContent('Risk register not available');
        expect(riskTile).toHaveTextContent('—');
        expect(screen.getByTestId('assurance-headline').textContent).not.toMatch(/risks above/i);
    });

    it('hides an area the server says the viewer may not see, even if the UI permission map allows it', () => {
        render(
            <CockpitLayout
                cockpit={cockpit({
                    assurance: assurance({
                        obligations_overdue: { permitted: false, available: false, count: null, href: '/governance/compliance?status=overdue' },
                    }),
                })}
                workflow={workflow}
                myWork={myWork}
                permissions={memberPermissions}
            />,
        );

        expect(screen.queryByRole('link', { name: /View overdue requirements/ })).not.toBeInTheDocument();
        expect(screen.getByTestId('assurance-headline').textContent).not.toMatch(/requirement/i);
    });

    it('applies the Show filter to what is visible', () => {
        const { rerender } = render(
            <CockpitLayout
                cockpit={cockpit()}
                workflow={workflow}
                myWork={myWork}
                permissions={memberPermissions}
                view="mine"
            />,
        );

        expect(screen.getByText('Next meeting')).toBeInTheDocument();
        expect(screen.queryByText('Board assurance')).not.toBeInTheDocument();
        expect(screen.queryByTestId('priority-overview')).not.toBeInTheDocument();

        rerender(
            <CockpitLayout
                cockpit={cockpit()}
                workflow={workflow}
                myWork={myWork}
                permissions={memberPermissions}
                view="board"
            />,
        );

        expect(screen.queryByText('Next meeting')).not.toBeInTheDocument();
        expect(screen.getByText('Board assurance')).toBeInTheDocument();
        expect(screen.getByTestId('priority-overview')).toBeInTheDocument();
    });

    it('counts the header "Needs the board\'s attention" meter from exactly what the summary flags', () => {
        const payload = assurance();
        const extras = assuranceExtras({ privacy_data: card('privacy_data', 'Privacy and personal information', 'warning') });
        const attention = assuranceAttention(payload, assuranceVisibility(payload, managerPermissions), extras);

        // 12 risks + 1 requirement + the privacy area; the budget source is not available.
        expect(attention.total).toBe(14);
        expect(attention.unavailable).toBe(1);
        expect(attention.parts).toEqual([
            "12 risks above the board's limit",
            '1 overdue requirement',
            'Privacy and personal information (needs watching)',
        ]);
    });
});
