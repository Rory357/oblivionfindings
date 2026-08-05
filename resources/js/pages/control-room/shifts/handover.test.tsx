import { render, screen } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import ShiftHandover from './handover';

const inertia = vi.hoisted(() => ({
    patch: vi.fn(),
    post: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    router: inertia,
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

const requiredAlert = {
    id: 41,
    reference_number: 'CR-2026-4100',
    summary: 'A medium alert changed during this shift.',
    severity: 'medium',
    site: { id: 9, name: 'Kauri House' },
    person: null,
    assignee: null,
    sla: { status: 'on_track', next_deadline_at: null },
    journey: {
        incident_reference: null,
        health_safety_reference: null,
        handover_status: null,
    },
    next_action: {
        label: 'Continue Control Room response',
        href: '/control-room/alerts/41',
    },
    href: '/control-room/alerts/41',
    tasks: [],
    handover_reasons: [
        {
            key: 'lifecycle_changed',
            label: 'Operational state changed during this shift',
        },
    ],
};

const carryForward = {
    total: 118,
    by_severity: { critical: 2, high: 14, medium: 62, low: 40 },
    by_queue: [{ id: null, name: 'Unassigned', total: 118 }],
    oldest_created_at: '2026-07-12T08:00:00+12:00',
    breached_count: 3,
    href: '/control-room/alerts?lens=active&handover=carry-forward',
    signature: 'a'.repeat(64),
};

const baseProps = {
    shift: {
        id: 7,
        name: 'Outgoing control desk',
        starts_at: '2026-07-17T02:00:00+12:00',
        ends_at: null,
        status: 'active',
        shift_lead: { id: 1, name: 'Outgoing Lead' },
        team_members: [],
        open_alerts_at_start: 120,
        alerts_created: 1,
        alerts_resolved: 0,
        alerts_escalated: 0,
        duration_minutes: 480,
        handover_status: 'none',
        handover_version: 1,
        handover_prepared_at: null,
        handover_snapshot: null,
        draft: {},
        incoming_lead: null,
        is_stale: false,
        stale_after_hours: 16,
        can_override: false,
        can_prepare: true,
        can_accept: false,
    },
    openAlertsCount: 119,
    requiredAlerts: [requiredAlert],
    handoverCriteriaAt: '2026-07-17T10:00:00+12:00',
    handoverCriteria: requiredAlert.handover_reasons,
    carryForward,
    pinnedNotes: [],
    followupNotes: [],
    staff: [{ id: 2, name: 'Incoming Lead' }],
    eligibleLeads: [{ id: 2, name: 'Incoming Lead' }],
} as unknown as ComponentProps<typeof ShiftHandover>;

describe('bounded Control Room shift handover', () => {
    it('reviews only changed or decision-relevant work and explicitly acknowledges the summary', () => {
        render(<ShiftHandover {...baseProps} />);

        expect(
            screen.getByRole('heading', {
                name: 'Review changed and decision-relevant work',
            }),
        ).toBeInTheDocument();
        expect(screen.getByText('CR-2026-4100')).toBeInTheDocument();
        expect(
            screen.getByText('Operational state changed during this shift'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                '118 unchanged active alerts will carry forward as a summary. You do not need to open each one.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('checkbox', {
                name: 'Acknowledge 118 unchanged active alerts',
            }),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Review every critical and high alert'),
        ).not.toBeInTheDocument();
    });

    it('renders the frozen required set and carry-forward summary from the prepared snapshot', () => {
        const preparedProps = {
            ...baseProps,
            shift: {
                ...baseProps.shift,
                handover_status: 'prepared',
                handover_prepared_at: '2026-07-17T10:00:00+12:00',
                incoming_lead: { id: 2, name: 'Incoming Lead' },
                can_prepare: false,
                handover_snapshot: {
                    prepared_by: { id: 1, name: 'Outgoing Lead' },
                    prepared_at: '2026-07-17T10:00:00+12:00',
                    criteria_at: '2026-07-17T10:00:00+12:00',
                    criteria: requiredAlert.handover_reasons,
                    handover_notes: 'Continue the response.',
                    incoming_shift: {
                        name: 'Night response desk',
                        lead: { id: 2, name: 'Incoming Lead' },
                        team_members: [],
                    },
                    reviewed_alert_ids: [41],
                    required_alert_ids: [41],
                    priority_alert_ids: [],
                    alerts: [requiredAlert],
                    carry_forward: carryForward,
                    carry_forward_acknowledged: true,
                    pinned_notes: [],
                    followup_notes: [],
                },
            },
        } as unknown as ComponentProps<typeof ShiftHandover>;

        render(<ShiftHandover {...preparedProps} />);

        expect(
            screen.getByRole('heading', {
                name: 'Frozen required-work snapshot',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                '118 unchanged active alerts carried forward as an acknowledged summary.',
            ),
        ).toBeInTheDocument();
        expect(screen.getByText('CR-2026-4100')).toBeInTheDocument();
    });

    it('renders a safe recovery state for an unusable prepared snapshot', () => {
        render(
            <ShiftHandover
                {...baseProps}
                snapshotIssue="This prepared handover snapshot is incomplete or inconsistent."
                shift={{
                    ...baseProps.shift,
                    handover_status: 'prepared',
                    can_prepare: false,
                    can_accept: false,
                    handover_snapshot: null,
                }}
                requiredAlerts={[]}
            />,
        );

        expect(
            screen.getByRole('heading', {
                name: 'Prepared handover cannot be used',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/outgoing shift remains active/i),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'Accept and start my shift',
            }),
        ).not.toBeInTheDocument();
    });

    it('shows the audited stale-shift recovery boundary and requires an override reason', () => {
        render(
            <ShiftHandover
                {...baseProps}
                shift={{
                    ...baseProps.shift,
                    is_stale: true,
                    stale_after_hours: 16,
                    can_override: true,
                    can_prepare: true,
                }}
            />,
        );

        expect(
            screen.getByText(
                'This shift is stale. The named outgoing lead has not completed handover. An authorised manager may prepare it with an audited reason; the incoming lead must still accept it.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('textbox', {
                name: 'Audited override reason',
            }),
        ).toBeRequired();
    });
});
