import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import PlaybookShow from './show';

const inertia = vi.hoisted(() => ({
    visit: vi.fn(),
    get: vi.fn(),
    post: vi.fn(),
    reload: vi.fn(),
    breadcrumbs: [] as Array<{ title: string; href?: string }>,
    wizard: null as null | { playbook?: Record<string, unknown> | null },
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
        url: '/control-room/playbooks/7',
        props: { auth: { can: { controlRoom: { viewAny: true } } } },
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

// The wizard has its own behaviour; here we check it opens prefilled.
vi.mock('@/components/control-room/playbook-wizard', () => ({
    PlaybookWizard: (props: { playbook?: Record<string, unknown> | null }) => {
        inertia.wizard = props;
        return <div role="dialog" aria-label="Edit response procedure" />;
    },
}));

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    inertia.wizard = null;
    window.history.replaceState(null, '', '/');
});

const playbook = {
    id: 7,
    name: 'Reported outside the safe zone',
    code: 'outside_safe_zone',
    description: 'Check the person’s location and immediate safety.',
    category: 'safety',
    version: 3,
    is_active: true,
    auto_attach: true,
    trigger_alert_types: ['geofence_breach'],
    trigger_severities: ['high'],
    sla_acknowledge_minutes: 5,
    sla_response_minutes: 15,
    sla_resolution_minutes: 60,
    required_evidence: ['photo', 'note'],
    requires_approval: false,
    approval_roles: [],
    escalation_after_minutes: 20,
    escalation_targets: [],
    created_by: { id: 1, name: 'Aroha Ngata' },
    updated_by: null,
    created_at: '2026-10-01T00:00:00Z',
    updated_at: '2026-10-01T00:00:00Z',
    steps: [
        {
            id: 11,
            order: 1,
            title: 'Acknowledge alert',
            type: 'task',
            instructions: 'Acknowledge and review the alert context.',
            is_required: true,
            is_blocking: true,
            time_limit_minutes: 5,
            decision_options: null,
            notify_config: null,
            evidence_config: null,
        },
        {
            id: 12,
            order: 2,
            title: 'Record what was seen',
            type: 'evidence',
            instructions: null,
            is_required: true,
            is_blocking: false,
            time_limit_minutes: null,
            decision_options: null,
            notify_config: null,
            evidence_config: null,
        },
    ],
};

const props = {
    playbook,
    recentRuns: [
        {
            id: 90,
            alert_id: 418,
            alert: { id: 418, alert_type: 'geofence_breach', severity: 'high', status: 'resolved' },
            status: 'completed',
            current_step: 2,
            completed_steps: 2,
            total_steps: 2,
            progress: 100,
            started_at: '2026-10-14T06:12:00Z',
            completed_at: '2026-10-14T06:48:00Z',
            started_by: { id: 2, name: 'Jordan Peri' },
            completed_by: null,
        },
    ],
    categories: { safety: 'Safety' },
    stepTypes: { task: 'Task', evidence: 'Collect evidence' },
    can: { manage: true },
} satisfies ComponentProps<typeof PlaybookShow>;

describe('A response procedure record', () => {
    it('opens as a record with its own tabs, a back link and its state', () => {
        render(<PlaybookShow {...props} />);

        expect(
            screen.getByRole('heading', { level: 1, name: 'Reported outside the safe zone' }),
        ).toBeInTheDocument();
        expect(screen.getByText('Available · Version 3')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Back' })).toHaveAttribute(
            'href',
            '/control-room/playbooks',
        );
        expect(inertia.breadcrumbs.map((crumb) => crumb.title)).toEqual([
            'Home',
            'Control Room',
            'Settings',
            'Reported outside the safe zone',
        ]);
        const rail = screen.getByRole('tablist', { name: 'Procedure record' });
        expect(within(rail).getAllByRole('tab').map((tab) => tab.textContent?.replace(/\d+$/, ''))).toEqual([
            'Steps',
            'Required evidence',
            'Run history',
        ]);
        expect(document.body.textContent).not.toMatch(/playbook|\bSLA\b/i);
    });

    it('shows the steps with when it starts and its time targets alongside, hiding empty rows', () => {
        render(<PlaybookShow {...props} />);

        expect(screen.getByText('Acknowledge alert')).toBeInTheDocument();
        expect(screen.getByText('Must finish first')).toBeInTheDocument();
        expect(screen.getByText('Task · within 5 min')).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'When it starts' })).toBeInTheDocument();
        expect(screen.getByText('Yes, for matching new alerts')).toBeInTheDocument();
        const targets = screen.getByRole('heading', { name: 'Time targets' }).closest('[data-slot="card"]') as HTMLElement;
        expect(within(targets).getByText('1 hr')).toBeInTheDocument();
        expect(within(targets).getByText('20 min')).toBeInTheDocument();
        expect(screen.queryByText('Who signs off')).not.toBeInTheDocument();
        expect(screen.queryByText('Last changed')).not.toBeInTheDocument();
        expect(screen.queryByText('—')).not.toBeInTheDocument();
    });

    it('the run history tab lists each run, and rows open their alert', () => {
        render(<PlaybookShow {...props} />);

        fireEvent.click(screen.getByRole('tab', { name: /Run history/ }));
        const row = screen.getByRole('link', { name: 'Alert 418' }).closest('tr') as HTMLElement;
        expect(within(row).getByText('Done')).toBeInTheDocument();
        expect(within(row).getByText('2 of 2 steps')).toBeInTheDocument();
        expect(within(row).getByText('36 min')).toBeInTheDocument();
        expect(window.location.search).toBe('?tab=runs');

        fireEvent.contextMenu(row);
        fireEvent.click(screen.getByRole('menuitem', { name: 'Open alert' }));
        expect(inertia.visit).toHaveBeenCalledWith('/control-room/alerts/418');
    });

    it('Edit procedure opens the wizard prefilled, keeping the settings it has no fields for', () => {
        render(<PlaybookShow {...props} />);

        fireEvent.click(screen.getByRole('button', { name: 'Edit procedure' }));
        expect(screen.getByRole('dialog', { name: 'Edit response procedure' })).toBeInTheDocument();
        expect(inertia.wizard?.playbook).toMatchObject({
            id: 7,
            trigger_alert_types: ['geofence_breach'],
            trigger_severities: ['high'],
            escalation_after_minutes: 20,
        });
    });

    it('turning it off asks first in red', () => {
        render(<PlaybookShow {...props} />);

        fireEvent.click(screen.getByRole('button', { name: 'Turn off' }));
        const confirm = screen.getByRole('alertdialog', { name: 'Turn off this procedure?' });
        fireEvent.click(within(confirm).getByRole('button', { name: 'Turn off procedure' }));
        expect(inertia.post).toHaveBeenCalledWith(
            '/control-room/playbooks/7/toggle-active',
            {},
            expect.any(Object),
        );
    });

    it('people who can only view read it without edit controls', () => {
        render(<PlaybookShow {...props} can={{ manage: false }} />);

        expect(screen.getByText('Acknowledge alert')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Edit procedure' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Turn off' })).not.toBeInTheDocument();
    });
});
