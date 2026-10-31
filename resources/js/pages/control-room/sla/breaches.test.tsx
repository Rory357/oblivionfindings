import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import SlaBreaches from './breaches';

const inertia = vi.hoisted(() => ({
    visit: vi.fn(),
    get: vi.fn(),
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
        url: '/control-room/sla/breaches',
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

const breach = {
    id: 1,
    alert_id: 418,
    alert_reference: 'CR-2026-00418',
    alert_type: 'geofence_breach',
    severity: 'high',
    source: 'personal_tracker',
    sla_name: 'High alert response',
    sla_code: 'HIGH',
    breach_types: ['acknowledge'],
    acknowledge_deadline: '2026-10-20T20:29:00Z',
    acknowledged_at: null,
    acknowledge_variance_minutes: null,
    response_deadline: null,
    responded_at: null,
    response_variance_minutes: null,
    resolution_deadline: null,
    resolved_at: null,
    resolution_variance_minutes: null,
    first_breach_at: '2026-10-20T20:29:00Z',
};

const props = {
    breaches: {
        data: [
            breach,
            {
                ...breach,
                id: 2,
                alert_id: 397,
                alert_reference: 'CR-2026-00397',
                alert_type: 'vehicle_overdue',
                breach_types: ['response'],
                response_deadline: '2026-10-19T03:20:00Z',
                responded_at: '2026-10-19T03:31:00Z',
                response_variance_minutes: 11,
                sla_name: null,
                sla_code: null,
            },
        ],
        links: [],
        current_page: 1,
        last_page: 1,
        total: 2,
        from: 1,
        to: 2,
    },
    stats: { total: 2, acknowledge: 1, response: 1, resolution: 0 },
    filters: {
        date_from: '2026-09-24',
        date_to: '2026-10-24',
        severity: null,
        breach_type: null,
    },
} satisfies ComponentProps<typeof SlaBreaches>;

describe('Reports › Target breaches', () => {
    it('opens on the Target breaches tab of Reports', () => {
        render(<SlaBreaches {...props} />);

        expect(screen.getByRole('heading', { level: 1, name: 'Reports' })).toBeInTheDocument();
        expect(inertia.breadcrumbs.map((crumb) => crumb.title)).toEqual([
            'Home',
            'Control Room',
            'Reports',
        ]);
        const rail = screen.getByRole('tablist', { name: 'Reports views' });
        expect(within(rail).getByRole('tab', { name: /Target breaches/ })).toHaveAttribute(
            'aria-selected',
            'true',
        );
        expect(screen.getByRole('heading', { name: 'Missed response targets' })).toBeInTheDocument();
    });

    it('keeps deadline, actual and variance exactly, with what happened before the reference', () => {
        render(<SlaBreaches {...props} />);

        const first = screen.getByRole('link', { name: 'CR-2026-00418' }).closest('tr') as HTMLElement;
        expect(within(first).getByText('Geofence breach')).toBeInTheDocument();
        expect(within(first).getByText('Pending')).toBeInTheDocument();
        expect(within(first).getByText('Ongoing')).toBeInTheDocument();
        const second = screen.getByRole('link', { name: 'CR-2026-00397' }).closest('tr') as HTMLElement;
        expect(within(second).getByText('11m late')).toBeInTheDocument();
        // A missing response target leaves the cell empty, never "-".
        expect(within(second).queryByText('-')).not.toBeInTheDocument();
    });

    it('header numbers filter by which target was missed; dates use the approved pickers', () => {
        render(<SlaBreaches {...props} />);

        fireEvent.click(screen.getByRole('button', { name: 'Show missed response targets' }));
        expect(inertia.get).toHaveBeenCalledWith(
            '/control-room/sla/breaches',
            { date_from: '2026-09-24', date_to: '2026-10-24', breach_type: 'response' },
            expect.any(Object),
        );
        expect(screen.getByRole('button', { name: 'From: 24 Sep 2026' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'To: 24 Oct 2026' })).toBeInTheDocument();
        expect(document.querySelector('input[type="date"]')).toBeNull();
    });

    it('rows keep both the kebab and the right-click menu with the same actions', () => {
        render(<SlaBreaches {...props} />);

        const row = screen.getByRole('link', { name: 'CR-2026-00418' }).closest('tr') as HTMLElement;
        fireEvent.contextMenu(row);
        expect(screen.getByRole('menuitem', { name: 'Open alert' })).toBeInTheDocument();
        expect(screen.getByRole('menuitem', { name: 'Copy alert reference' })).toBeInTheDocument();
    });

    it('says plainly when nothing matches', () => {
        render(
            <SlaBreaches
                {...props}
                breaches={{ ...props.breaches, data: [], total: 0, from: null, to: null }}
                stats={{ total: 0, acknowledge: 0, response: 0, resolution: 0 }}
            />,
        );

        expect(screen.getByText('No missed response targets')).toBeInTheDocument();
        expect(screen.queryByText(/Great work/)).not.toBeInTheDocument();
    });
});
