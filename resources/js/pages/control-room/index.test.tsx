import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import {
    ControlRoomDashboardView,
    type ControlRoomDashboardProps,
} from '@/pages/control-room/index';

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
    post: vi.fn(),
    url: '/control-room',
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
        props: { auth: { can: { controlRoom: { viewAny: true, alertsManage: true } } } },
    }),
    useForm: () => ({ data: {}, errors: {}, setData: vi.fn(), post: vi.fn(), processing: false, isDirty: false, transform: vi.fn() }),
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

describe('Live response › Active', () => {
    it('opens with the standard header: title, linked numbers and the view tabs', () => {
        render(<ControlRoomDashboardView {...props()} />);

        expect(screen.getByRole('heading', { level: 1, name: 'Live response' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Show critical alerts' })).toHaveAttribute(
            'href',
            '/control-room?severity=critical',
        );
        expect(screen.getByRole('link', { name: 'Show alerts no one has taken' })).toHaveAttribute(
            'href',
            '/control-room?assigned_to=unassigned',
        );
        expect(screen.getByRole('link', { name: 'Duty handover' })).toHaveAttribute(
            'href',
            '/control-room/shifts',
        );
        expect(screen.getByRole('link', { name: 'Add alert' })).toHaveAttribute(
            'href',
            '/control-room/alerts?new=1',
        );
        for (const tab of ['Needs action', 'Mine', 'Escalated', 'Snoozed', 'Incident reports', 'Done']) {
            expect(screen.getAllByText(tab).length).toBeGreaterThan(0);
        }
    });

    it('puts the urgent top alert in "Needs you now" and previews it', () => {
        render(<ControlRoomDashboardView {...props()} />);

        const card = screen.getByLabelText('Needs you now: Aroha Ngata');
        expect(within(card).getByText('Missed welfare check')).toBeInTheDocument();
        expect(within(card).getByText('No one on it yet')).toBeInTheDocument();
        expect(within(card).getByRole('button', { name: 'Acknowledge' })).toBeInTheDocument();
        expect(within(card).getByRole('button', { name: 'Take it' })).toBeInTheDocument();
        // The rest of the list sits under it.
        expect(screen.getByRole('heading', { name: 'Up next' })).toBeInTheDocument();
        expect(screen.getByText('Nothing else is waiting')).toBeInTheDocument();
        // Nothing was chosen, so the top alert loads into the preview.
        expect(inertia.get).toHaveBeenCalledWith(
            expect.stringContaining('alert=9'),
            {},
            expect.objectContaining({ only: ['detail'], replace: true }),
        );
        const preview = screen.getByLabelText('Selected alert CR-2026-0009');
        expect(within(preview).getByRole('link', { name: /Open full record/ })).toHaveAttribute(
            'href',
            '/control-room/alerts/9',
        );
    });

    it('keeps a calm board calm: no card when the top alert is not urgent', () => {
        const base = props();
        const calm = { ...base.worklist.data[0], severity: 'low' as const, sla: { status: 'on_track' as const, next_deadline_at: null } };
        render(
            <ControlRoomDashboardView
                {...props({ worklist: { ...base.worklist, data: [calm] } })}
            />,
        );
        expect(screen.queryByLabelText(/Needs you now/)).not.toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Needs action' })).toBeInTheDocument();
        expect(screen.getAllByText('Missed welfare check').length).toBeGreaterThan(0);
    });

    it('says plainly when the worklist could not refresh', () => {
        render(
            <ControlRoomDashboardView
                {...props({
                    freshness: { updated_at: '2020-01-01T00:00:00Z', stale_after_seconds: 90 },
                })}
            />,
        );
        expect(screen.getByText(/couldn.t refresh/)).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
        expect(inertia.reload).toHaveBeenCalledWith(
            expect.objectContaining({ only: expect.arrayContaining(['worklist', 'detail']) }),
        );
    });
});
