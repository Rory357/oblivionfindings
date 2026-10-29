import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import Register from './index';

const state = vi.hoisted(() => ({ get: vi.fn(), tab: 'sessions' }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        children,
        href,
        ...props
    }: {
        children: ReactNode;
        href: string;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    usePage: () => ({
        url: `/health-safety/lone-workers?tab=${state.tab}&user_id=7`,
        props: { auth: { can: {} } },
    }),
    router: { get: state.get, on: () => () => {} },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
type Props = ComponentProps<typeof Register>;
const page = {
    data: [],
    links: [],
    current_page: 1,
    last_page: 1,
    per_page: 25,
    total: 0,
    from: null,
    to: null,
};
function props(): Props {
    return {
        tab: 'sessions',
        sessions: page,
        alerts: page,
        hero: {
            clusters: {
                live: { active: 0, overdue: 0, emergency: 0, ending_soon: 0 },
                alerts: {
                    today: 0,
                    awaiting_ack: 0,
                    unresolved: 0,
                    no_recent_checkin: 0,
                },
            },
            badges: {
                checked_in: 0,
                monitored_total: 0,
                overdue: 0,
                emergency_active: false,
                after_hours: false,
            },
            lone_shifts_unmonitored: 0,
        },
        coverage: {
            state: 'unknown',
            expected_count: null,
            required_count: 0,
            monitored_count: 0,
            unmonitored_count: 0,
            unknown_count: 1,
            active_duty_count: 1,
            checked_at: null,
            upcoming_expected_count: 0,
            upcoming_unknown_count: 0,
        },
        filters: {
            site_id: null,
            status: null,
            user_id: 7,
            period: 'all',
            q: null,
        },
        pickers: {
            sites: [],
            clients: [],
            workers: [{ id: 7, name: 'Mere', site_ids: [] }],
            shifts: [],
            check_in_statuses: [],
            check_in_intervals: [],
        },
        can: { view: true, manage: false, view_control_room: true },
    };
}
let captured: Blob;
beforeEach(() => {
    state.tab = 'sessions';
    state.get.mockReset();
    URL.createObjectURL = vi.fn((blob) => {
        captured = blob as Blob;
        return 'blob:synthetic';
    });
    URL.revokeObjectURL = vi.fn();
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});
const exported = () =>
    new Promise<string>((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result));
        reader.onerror = reject;
        reader.readAsText(captured);
    });
it('clears the unsupported worker filter when changing to Responses and does not offer an ignored selector', () => {
    const view = render(<Register {...props()} />);
    // Drawing D4: the hub's views on the header rail, Sessions / Responses as tier-two tabs.
    expect(
        screen.getByRole('tablist', { name: 'Risks and prevention views' }),
    ).toBeInTheDocument();
    expect(
        screen.getByRole('tablist', { name: 'Lone-worker views' }),
    ).toBeInTheDocument();
    fireEvent.click(screen.getByRole('tab', { name: 'Responses' }));
    expect(state.get.mock.calls[0][1]).not.toHaveProperty('user_id');
    state.tab = 'alerts';
    view.rerender(<Register {...props()} tab="alerts" />);
    expect(
        screen.queryByRole('combobox', { name: 'Worker' }),
    ).not.toBeInTheDocument();
});
it('keeps the retained response Client/Source export columns without inventing a hidden Client label', async () => {
    state.tab = 'alerts';
    const session = {
        id: 1,
        user: { id: 7, name: '=Formula' },
        site: { id: 2, name: 'Kauri' },
        client: null,
        started_at: null,
        expected_end_at: null,
        last_check_in_at: null,
        status: 'emergency',
        activity_description: null,
        check_in_interval_minutes: 30,
        location: null,
    };
    const alert = {
        id: 'cr_1',
        session,
        type: 'emergency',
        status: 'acknowledged',
        canonical_status: 'triaging',
        source: 'control_room' as const,
        triggered_at: null,
        notes: null,
    };
    render(
        <Register
            {...props()}
            tab="alerts"
            alerts={{
                ...page,
                data: [
                    alert,
                    {
                        ...alert,
                        id: 'cr_2',
                        session: {
                            ...session,
                            client: { id: 3, name: 'Readable client' },
                        },
                    },
                ],
                total: 2,
                from: 1,
                to: 2,
            }}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Export this page' }));
    const csv = await exported();
    expect(csv).toContain('"Client","Source"');
    expect(csv).toContain('"Emergency","","Control Room","Being assessed"');
    expect(csv).toContain('"Readable client","Control Room"');
    expect(csv).toContain('"\'=Formula"');
});
it('exports the same derived overdue state that staff see on the session row', async () => {
    const row = {
        id: 1,
        reference: 'LW-1',
        status: 'active',
        user: { id: 7, name: 'Mere' },
        site: null,
        client: null,
        activity_description: null,
        started_at: null,
        expected_end_at: null,
        ended_at: null,
        is_check_in_overdue: true,
        observation: {
            state: 'no_check_in',
            real_check_in_at: null,
            received_at: null,
            receipt_source: null,
            startup_baseline_at: null,
            next_due_at: null,
            monitor: {
                state: 'unknown',
                checked_at: null,
                failed_at: null,
                failure_code: null,
            },
        },
    };
    render(
        <Register
            {...props()}
            sessions={{ ...page, data: [row], total: 1, from: 1, to: 1 }}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Export this page' }));
    expect(await exported()).toContain('"Check-in overdue"');
});
