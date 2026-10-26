import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ControlRoomShifts from './shifts';

const inertia = vi.hoisted(() => ({
    visit: vi.fn(),
    post: vi.fn(),
    get: vi.fn(),
    reload: vi.fn(),
    formPost: vi.fn(),
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
        url: '/control-room/shifts',
        props: { auth: { can: { controlRoom: { viewAny: true, alertsManage: true } } } },
    }),
    useForm: (initial: Record<string, unknown>) => ({
        data: initial,
        errors: {},
        setData: vi.fn(),
        post: inertia.formPost,
        transform: vi.fn(),
        reset: vi.fn(),
        clearErrors: vi.fn(),
        processing: false,
        isDirty: false,
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
});

const activeShift = {
    id: 7,
    name: 'Friday day duty',
    starts_at: '2026-10-23T18:00:00Z',
    ends_at: null,
    status: 'active',
    shift_lead: { id: 1, name: 'Jordan Peri' },
    team_members: [
        { id: 2, name: 'Rāwiri Kingi' },
        { id: 3, name: 'Aroha Ngata' },
    ],
    open_alerts_at_start: 4,
    alerts_created: 7,
    alerts_resolved: 5,
    alerts_escalated: 1,
    handover_status: 'none' as const,
    handover_version: 1,
    handover_prepared_at: null,
    is_stale: false,
    stale_after_hours: 16,
    incoming_lead: null,
    actions: { can_open_handover: true, can_add_note: true, can_copy_summary: true },
};

const props = {
    activeShift,
    notes: [
        {
            id: 11,
            type: 'decision',
            content: 'Keep Kahu on 15-minute checks until the GP review',
            is_pinned: true,
            requires_followup: false,
            followup_at: null,
            alert_id: 417,
            alert_reference: 'CR-2026-00417',
            user: { id: 1, name: 'Jordan Peri' },
            created_at: '2026-10-23T20:20:00Z',
        },
    ],
    recentShifts: [
        {
            id: 6,
            name: 'Night duty 22 Oct',
            starts_at: '2026-10-22T09:00:00Z',
            ends_at: '2026-10-22T17:00:00Z',
            status: 'completed',
            shift_lead: { id: 2, name: 'Rāwiri Kingi' },
            alerts_created: 3,
            alerts_resolved: 3,
            alerts_escalated: 0,
            duration_minutes: 480,
            actions: { can_copy_summary: true },
        },
    ],
    openAlertsCount: 9,
    criticalAlertsCount: 2,
    staff: [
        { id: 1, name: 'Jordan Peri' },
        { id: 2, name: 'Rāwiri Kingi' },
    ],
    eligibleLeads: [{ id: 1, name: 'Jordan Peri' }],
    can: { manage: true },
} satisfies ComponentProps<typeof ControlRoomShifts>;

describe('Duty handover › Current duty', () => {
    it('opens in the Control Room frame with linked numbers and the one main action', () => {
        render(<ControlRoomShifts {...props} />);

        expect(screen.getByRole('heading', { level: 1, name: 'Duty handover' })).toBeInTheDocument();
        expect(inertia.breadcrumbs.map((crumb) => crumb.title)).toEqual([
            'Home',
            'Control Room',
            'Duty handover',
        ]);
        expect(screen.getByRole('link', { name: 'Show the open alerts' })).toHaveAttribute(
            'href',
            '/control-room',
        );
        expect(screen.getByRole('link', { name: 'Show critical alerts' })).toHaveAttribute(
            'href',
            '/control-room?severity=critical',
        );
        expect(screen.getByRole('link', { name: 'Prepare the handover' })).toHaveAttribute(
            'href',
            '/control-room/shifts/7/handover',
        );
        // Preparing a handover is a full page, not a pop-up.
        expect(screen.getByRole('link', { name: 'Prepare handover' })).toHaveAttribute(
            'href',
            '/control-room/shifts/7/handover',
        );
        // Planned staffing stays in Rostering.
        expect(screen.getByRole('link', { name: 'Open Rostering' })).toHaveAttribute(
            'href',
            '/operations/rostering',
        );
    });

    it('shows who is responsible now and what happened on this duty, once', () => {
        render(<ControlRoomShifts {...props} />);

        const duty = screen.getByRole('heading', { name: /Friday day duty/ }).closest('[data-slot="card"]');
        expect(duty).not.toBeNull();
        const card = within(duty as HTMLElement);
        expect(card.getByText('Jordan Peri')).toBeInTheDocument();
        expect(card.getByText('Rāwiri Kingi · Aroha Ngata')).toBeInTheDocument();
        expect(card.getByText('7 alerts created · 5 resolved · 1 escalated')).toBeInTheDocument();
        expect(
            screen.getByText(/Preparing a handover does not transfer ownership/),
        ).toBeInTheDocument();
        expect(screen.getByText('Keep Kahu on 15-minute checks until the GP review')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'CR-2026-00417' })).toHaveAttribute(
            'href',
            '/control-room/alerts/417',
        );
        expect(screen.getByRole('heading', { name: 'Past duties' })).toBeInTheDocument();
    });

    it('adds an operator note through the kit pop-up', () => {
        render(<ControlRoomShifts {...props} />);

        fireEvent.click(screen.getByRole('button', { name: 'Add operator note' }));
        const dialog = screen.getByRole('dialog', { name: 'Add operator note' });
        expect(within(dialog).getByText('Friday day duty')).toBeInTheDocument();
        expect(within(dialog).getByRole('checkbox', { name: /Pin note/ })).toBeInTheDocument();
        expect(within(dialog).getByRole('checkbox', { name: /Requires follow-up/ })).toBeInTheDocument();
        // Cancel first, the main button names the action.
        const buttons = within(dialog)
            .getAllByRole('button')
            .map((button) => button.textContent?.trim())
            .filter((label) => label === 'Cancel' || label === 'Add note');
        expect(buttons).toEqual(['Cancel', 'Add note']);
    });

    it('with no duty running, offers one Start duty form and hides the empty side panel', () => {
        render(<ControlRoomShifts {...props} activeShift={null} notes={[]} recentShifts={[]} />);

        expect(screen.getByText('No duty is running')).toBeInTheDocument();
        expect(screen.queryByRole('heading', { name: 'Past duties' })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Prepare handover' })).not.toBeInTheDocument();

        fireEvent.click(screen.getAllByRole('button', { name: 'Start duty' })[0]);
        const dialog = screen.getByRole('dialog', { name: 'Start duty' });
        expect(within(dialog).getByLabelText(/Duty name/)).toBeInTheDocument();
        expect(within(dialog).getByRole('button', { name: 'Start duty' })).toBeDisabled();
    });

    it('people who cannot manage alerts see the duty but no actions', () => {
        render(
            <ControlRoomShifts
                {...props}
                can={{ manage: false }}
                activeShift={{
                    ...activeShift,
                    actions: { can_open_handover: false, can_add_note: false, can_copy_summary: true },
                }}
            />,
        );

        expect(screen.queryByRole('button', { name: 'Add operator note' })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Prepare handover' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Start duty' })).not.toBeInTheDocument();
    });
});
