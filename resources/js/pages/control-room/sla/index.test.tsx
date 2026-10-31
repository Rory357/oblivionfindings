import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import SlaIndex from './index';

const inertia = vi.hoisted(() => ({
    visit: vi.fn(),
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    reload: vi.fn(),
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
        url: '/control-room/sla',
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

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

const critical = {
    id: 1,
    name: 'Critical alerts',
    code: 'CRIT-5',
    description: 'Falls and SOS calls',
    alert_types: ['sos_alert', 'fall_detected'],
    severities: ['critical'],
    sources: [],
    acknowledge_target_minutes: 5,
    response_target_minutes: 15,
    resolution_target_minutes: 60,
    business_hours_only: true,
    business_hours: { start: '08:00', end: '18:00', days: [1, 2, 3, 4, 5] },
    escalate_on_acknowledge_breach: true,
    escalate_on_response_breach: true,
    escalate_on_resolution_breach: true,
    breach_notify_roles: ['admin', 'coordinator'],
    is_active: true,
    total_alerts: 12,
    compliance: { acknowledge_pct: 91.7, response_pct: 83.3, resolution_pct: null },
};

const low = {
    ...critical,
    id: 2,
    name: 'Low priority',
    code: 'LOW',
    description: null,
    alert_types: [],
    severities: [],
    resolution_target_minutes: null,
    business_hours_only: false,
    business_hours: null,
    escalate_on_acknowledge_breach: false,
    escalate_on_response_breach: false,
    escalate_on_resolution_breach: false,
    breach_notify_roles: [],
    is_active: false,
    total_alerts: 0,
    compliance: { acknowledge_pct: null, response_pct: null, resolution_pct: null },
};

const props = {
    slaDefinitions: [critical, low],
    can: { manage: true },
} satisfies ComponentProps<typeof SlaIndex>;

const row = (name: string) => screen.getByText(name).closest('tr') as HTMLElement;

describe('Settings › Response targets', () => {
    it('opens on the Response targets tab of Settings, in plain words', () => {
        render(<SlaIndex {...props} />);

        expect(screen.getByRole('heading', { level: 1, name: 'Settings' })).toBeInTheDocument();
        expect(inertia.breadcrumbs.map((crumb) => crumb.title)).toEqual([
            'Home',
            'Control Room',
            'Settings',
        ]);
        const rail = screen.getByRole('tablist', { name: 'Settings views' });
        expect(within(rail).getByRole('tab', { name: /Response targets/ })).toHaveAttribute(
            'aria-selected',
            'true',
        );
        expect(screen.getByRole('link', { name: /Target breaches/ })).toHaveAttribute(
            'href',
            '/control-room/sla/breaches',
        );
        expect(document.body.textContent).not.toMatch(/\bSLA\b/);
    });

    it('header numbers filter the list in place', () => {
        render(<SlaIndex {...props} />);

        fireEvent.click(screen.getByRole('button', { name: 'Show response targets that are off' }));
        expect(screen.queryByText('Critical alerts')).not.toBeInTheDocument();
        expect(screen.getByText('Low priority')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Show every response target' }));
        expect(screen.getByText('Critical alerts')).toBeInTheDocument();
    });

    it('keeps the three targets exactly and leaves empty ones blank', () => {
        render(<SlaIndex {...props} />);

        const first = row('Critical alerts');
        expect(within(first).getByText('5 min')).toBeInTheDocument();
        expect(within(first).getByText('15 min')).toBeInTheDocument();
        expect(within(first).getByText('1 hr')).toBeInTheDocument();
        expect(within(first).getByText('92% met')).toBeInTheDocument();
        expect(within(first).getByText('Mon–Fri, 08:00 AM – 06:00 PM')).toBeInTheDocument();
        expect(within(first).getByText('Escalates when any target is missed')).toBeInTheDocument();

        const second = row('Low priority');
        expect(within(second).getByText('All alerts')).toBeInTheDocument();
        expect(within(second).queryByText('-')).not.toBeInTheDocument();
        expect(within(second).queryByText('—')).not.toBeInTheDocument();
    });

    it('Add response target opens the kit form with its sections and the approved time picker', () => {
        render(<SlaIndex {...props} />);

        fireEvent.click(screen.getByRole('button', { name: 'Add response target' }));
        const dialog = screen.getByRole('dialog', { name: 'Add response target' });
        for (const section of [
            'Details',
            'Matching criteria',
            'Target times (minutes)',
            'Business hours',
            'Escalation on breach',
            'Status',
        ]) {
            expect(within(dialog).getByRole('heading', { name: new RegExp(section.replace(/[()]/g, '\\$&')) })).toBeInTheDocument();
        }

        fireEvent.click(within(dialog).getByRole('switch', { name: 'Only count business hours' }));
        expect(within(dialog).getByRole('button', { name: 'Start time: 08:00 AM' })).toBeInTheDocument();
        expect(within(dialog).getByRole('button', { name: 'End time: 06:00 PM' })).toBeInTheDocument();
        expect(document.querySelector('input[type="time"]')).toBeNull();

        fireEvent.change(within(dialog).getByLabelText(/^Name/), { target: { value: 'Medium alerts' } });
        fireEvent.change(within(dialog).getByLabelText(/^Short code/), { target: { value: 'MED' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Save target' }));

        expect(inertia.post).toHaveBeenCalledWith(
            '/control-room/sla',
            expect.objectContaining({
                name: 'Medium alerts',
                code: 'MED',
                business_hours_only: true,
                business_hours: { start: '08:00', end: '18:00', days: [1, 2, 3, 4, 5] },
                is_active: true,
            }),
            expect.any(Object),
        );
    });

    it('turning a target off asks first, in red', () => {
        render(<SlaIndex {...props} />);

        fireEvent.click(screen.getByRole('switch', { name: 'Response target Critical alerts on' }));
        expect(inertia.post).not.toHaveBeenCalled();
        const confirm = screen.getByRole('alertdialog', { name: 'Turn off this response target?' });
        fireEvent.click(within(confirm).getByRole('button', { name: 'Turn off target' }));
        expect(inertia.post).toHaveBeenCalledWith(
            '/control-room/sla/1/toggle-active',
            {},
            expect.any(Object),
        );
    });

    it('turning a target back on is direct', () => {
        render(<SlaIndex {...props} />);

        fireEvent.click(screen.getByRole('switch', { name: 'Response target Low priority on' }));
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
        expect(inertia.post).toHaveBeenLastCalledWith(
            '/control-room/sla/2/toggle-active',
            {},
            expect.any(Object),
        );
    });

    it('rows keep the kebab and the right-click menu with the same actions', () => {
        render(<SlaIndex {...props} />);

        fireEvent.contextMenu(row('Critical alerts'));
        expect(screen.getByRole('menuitem', { name: 'Edit response target' })).toBeInTheDocument();
        expect(screen.getByRole('menuitem', { name: 'Turn off' })).toBeInTheDocument();
    });

    it('people who can only view see the targets without edit controls', () => {
        render(<SlaIndex {...props} can={{ manage: false }} />);

        expect(screen.getByText('Critical alerts')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Add response target' })).not.toBeInTheDocument();
        expect(screen.queryByRole('switch')).not.toBeInTheDocument();
        expect(within(row('Low priority')).getByText('Off')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Actions for/ })).not.toBeInTheDocument();
    });
});
