import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import {
    ControlRoomDashboardView,
    type ControlRoomDashboardProps,
} from '@/pages/control-room/index';
import { FreshnessIndicator } from './service-health-panel';

const { reload } = vi.hoisted(() => ({ reload: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: {
        get: vi.fn(),
        reload,
        visit: vi.fn(),
    },
}));

vi.mock('@/components/control-room/alert-workspace-dialog', () => ({
    AlertWorkspaceDialog: () => null,
}));

vi.mock('@/components/control-room/command-centre-tabs', () => ({
    CommandCentreTabs: () => (
        <nav aria-label="Command centre views">Desk tabs</nav>
    ),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

function props(
    overrides: Partial<ControlRoomDashboardProps> = {},
): ControlRoomDashboardProps {
    return {
        hero: {
            active: 4,
            critical: 1,
            sla_breached: 1,
            unassigned: 2,
            oldest_open_at: '2026-07-15T07:00:00Z',
            last_24_hours: {
                alerts: 8,
                resolved: 5,
                avg_response_minutes: 12,
            },
        },
        worklist: {
            data: [
                {
                    id: 9,
                    reference_number: 'CR-2026-0009',
                    summary: 'Missed welfare check',
                    source: { key: 'manual', label: 'Manual' },
                    status: 'open',
                    severity: 'critical',
                    priority: {
                        level: 'critical',
                        rank: 0,
                        reason: 'Critical severity',
                    },
                    triggered_at: '2026-07-15T07:00:00Z',
                    next_deadline_at: '2026-07-15T07:15:00Z',
                    sla: {
                        status: 'breached',
                        next_deadline_at: '2026-07-15T07:15:00Z',
                    },
                    site: { id: 2, name: 'Kōwhai House' },
                    person: { id: 3, name: 'Aroha Ngata' },
                    assignee: null,
                    queue: { id: 1, name: 'Immediate response' },
                    journey: {
                        incident_reference: null,
                        health_safety_reference: null,
                        handover_status: null,
                    },
                    next_action: {
                        label: 'Continue response',
                        href: '/control-room/alerts/9',
                    },
                    actions: {
                        can_claim: true,
                        can_acknowledge: true,
                        can_move_queue: true,
                        can_escalate: true,
                        can_create_incident: true,
                        can_snooze: false,
                        can_unsnooze: false,
                        can_copy_reference: true,
                        incident_href: null,
                        health_safety_href: null,
                    },
                    href: '/control-room/alerts/9',
                },
            ],
            links: [],
            meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        },
        queues: [
            {
                id: 1,
                name: 'Immediate response',
                tier: 1,
                active: 3,
                critical: 1,
            },
        ],
        handover: {
            needs_incident: 1,
            awaiting_health_safety: 2,
            accepted_in_progress: 1,
            operational_complete_governance_open: 0,
        },
        activity: [],
        filters: {
            q: '',
            severity: '',
            source: '',
            assigned_to: '',
            site_id: '',
        },
        freshness: {
            updated_at: '2026-07-15T08:00:00Z',
            stale_after_seconds: 90,
        },
        sites: [{ id: 2, name: 'Kōwhai House' }],
        staff: [],
        can: { manage: true, assign: true, create: true, viewReports: true },
        detail: null,
        ...overrides,
    };
}

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

describe('Control Room desktop Desk composition', () => {
    it('puts workflow, hero, filters, priority work and continuity ahead of service health', () => {
        const { container } = render(<ControlRoomDashboardView {...props()} />);

        expect(
            screen.getByRole('navigation', {
                name: 'Incident response workflow',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'New alert' }),
        ).toBeInTheDocument();
        expect(screen.getByText('Now')).toBeInTheDocument();
        expect(screen.getByText('Continuity')).toBeInTheDocument();
        expect(
            screen.getByRole('search', { name: 'Filter priority work' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('heading', { name: 'Priority worklist' }),
        ).toBeInTheDocument();

        const order = [
            ...container.querySelectorAll('[data-desk-section]'),
        ].map((node) => node.getAttribute('data-desk-section'));
        expect(order.indexOf('workflow')).toBeLessThan(order.indexOf('hero'));
        expect(order.indexOf('hero')).toBeLessThan(order.indexOf('worklist'));
        expect(order.indexOf('continuity')).toBeLessThan(
            order.indexOf('service-health'),
        );
        expect(
            screen.queryByText('Historical performance'),
        ).not.toBeInTheDocument();
    });

    it('requests analytics only when opened and then renders the historical panel', () => {
        const { rerender } = render(<ControlRoomDashboardView {...props()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Open analytics' }));
        expect(reload).toHaveBeenCalledWith(
            expect.objectContaining({ only: ['analytics'] }),
        );

        rerender(
            <ControlRoomDashboardView
                {...props({
                    analytics: {
                        period: '7d',
                        volume: { daily_trend: [] },
                        sla: { compliance_pct: 92 },
                        escalation: { escalation_rate: 4 },
                        sites: [],
                    },
                })}
            />,
        );
        expect(screen.getByText('Historical performance')).toBeInTheDocument();
    });

    it('always gives freshness a readable Updated, Refreshing or Stale state', () => {
        const { rerender } = render(
            <FreshnessIndicator
                state="updated"
                updatedAt="2026-07-15T08:00:00Z"
            />,
        );
        expect(screen.getByText(/Updated/)).toBeInTheDocument();

        rerender(
            <FreshnessIndicator
                state="refreshing"
                updatedAt="2026-07-15T08:00:00Z"
            />,
        );
        expect(screen.getByText(/Refreshing/)).toBeInTheDocument();

        rerender(
            <FreshnessIndicator
                state="stale"
                updatedAt="2026-07-15T08:00:00Z"
            />,
        );
        expect(screen.getByText(/Stale/)).toBeInTheDocument();
    });
});
