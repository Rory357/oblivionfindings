import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SiteTechnologyShow, { type SiteTechnologyProps } from './show';
const navigation = vi.hoisted(() => ({ visit: vi.fn(), actorId: 7 }));
vi.mock('@inertiajs/react', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/react')>(
            '@inertiajs/react',
        );
    return {
        ...actual,
        Head: () => null,
        usePage: () => ({
            props: {
                auth: {
                    user: { id: navigation.actorId },
                    can: { 'securityDevices.devices.view': true },
                },
            },
        }),
        router: { ...actual.router, visit: navigation.visit },
    };
});
vi.mock('@/layouts/app-layout', () => ({
    default: ({
        children,
        breadcrumbs,
    }: {
        children: React.ReactNode;
        breadcrumbs: Array<{ title: string; href: string }>;
    }) => (
        <>
            <nav aria-label="Test breadcrumbs">
                {breadcrumbs.map((b) => (
                    <a key={b.href} href={b.href}>
                        {b.title}
                    </a>
                ))}
            </nav>
            {children}
        </>
    ),
}));
const baseDevice = {
    id: 11,
    device_uid: 'synthetic-router-11',
    name: 'Edge router',
    domain: 'it_infrastructure',
    category: 'networking',
    subcategory: 'router',
    manufacturer: 'Synthetic',
    model: 'Unverified fixture',
    status: 'active',
    health_status: 'unknown',
    provider: 'Fixture source',
    last_seen_at: null,
    battery_level: null,
    assigned_to: 'Synthetic Site',
    assignment_type: 'site',
    monitor_count: 1,
    monitoring_state: 'unknown',
};
function fixture(): SiteTechnologyProps {
    return {
        site: {
            id: 4,
            name: 'Synthetic Kauri Site',
            type: 'supported_living',
            city: 'Auckland',
            address: 'Synthetic address',
            is_active: true,
        },
        devices: [
            structuredClone(baseDevice),
            {
                ...structuredClone(baseDevice),
                id: 12,
                name: 'Core switch',
                device_uid: 'synthetic-switch-12',
                health_status: 'warning',
                monitoring_state: 'attention',
            },
        ],
        summary: {
            health: 'unknown',
            devices: 2,
            attention_devices: 1,
            offline_devices: 0,
            monitored_devices: 2,
            unmonitored_devices: 0,
            coverage_percent: 100,
            failed_monitors: 1,
            active_findings: 1,
            active_control_room_alerts: 2,
            open_it_work: 1,
            overdue_maintenance: 1,
            collector: {
                state: 'online',
                label: 'Site collector online',
                count: 1,
                last_seen_at: '2026-10-12T07:00:00Z',
            },
            last_change_at: '2026-10-12T06:00:00Z',
        },
        wan: {
            known: true,
            label: 'Classified WAN equipment',
            devices: [structuredClone(baseDevice)],
        },
        topology: {
            device_count: 2,
            edge_count: 1,
            is_complete: false,
            edges: [
                {
                    id: 31,
                    parent_name: 'Edge router',
                    child_name: 'Core switch',
                    type: 'network_link',
                    port: 'LAN 1',
                },
            ],
        },
        deviceGroups: [
            {
                id: 41,
                name: 'Network group',
                type: 'static',
                device_count: 2,
                href: '/security-devices/device-groups/41',
            },
        ],
        monitoring: {
            total_devices: 2,
            monitored_devices: 2,
            unmonitored_devices: 0,
            failed_monitors: 1,
            uncertain_monitors: 1,
            monitors: [
                {
                    id: 51,
                    device_id: 11,
                    device_name: 'Edge router',
                    name: 'Gateway reachability',
                    kind: 'icmp',
                    state: 'failed',
                    last_observation_at: '2026-10-12T07:00:00Z',
                },
                {
                    id: 52,
                    device_id: 12,
                    device_name: 'Core switch',
                    name: 'Switch status',
                    kind: 'snmp',
                    state: 'unknown',
                    last_observation_at: null,
                },
            ],
        },
        alerts: [
            {
                id: 61,
                reference: 'SYN-61',
                title: 'Available operational alert',
                severity: 'critical',
                status: 'active',
                triggered_at: '2026-10-12T07:00:00Z',
                href: '/control-room/alerts/61',
                access: { state: 'available', label: 'Available' },
            },
            {
                id: 62,
                reference: 'PRIVATE-62',
                title: 'Private alert detail',
                severity: 'critical',
                status: 'active',
                triggered_at: '2026-10-12T07:00:00Z',
                href: '/control-room/alerts/62',
                access: {
                    state: 'restricted',
                    label: 'Control Room alert access required',
                },
            },
        ],
        itWork: [
            {
                id: 71,
                reference: 'IT-71',
                title: 'Open network work',
                work_type: 'support',
                status: 'in_progress',
                priority: 'high',
                next_action: 'Review the retained readings',
                updated_at: '2026-10-12T07:00:00Z',
                href: '/it/tickets/71',
            },
        ],
        maintenance: [
            {
                id: 81,
                device_id: 12,
                device_name: 'Core switch',
                type: 'inspection',
                status: 'scheduled',
                description: 'Check switch power',
                scheduled_for: null,
                is_overdue: true,
            },
        ],
        collectors: [
            {
                id: 91,
                uuid: 'synthetic-collector-91',
                name: 'Synthetic Site collector',
                status: 'online',
                state: 'online',
                last_seen_at: '2026-10-12T07:00:00Z',
            },
        ],
        changes: [
            {
                key: 'synthetic-change-1',
                device_name: 'Core switch',
                summary: 'Configuration observation retained',
                at: '2026-10-12T06:00:00Z',
                href: '/security-devices/devices/12',
            },
        ],
        contacts: [
            {
                id: 101,
                type: 'technical',
                name: 'Synthetic contact',
                role: 'Site coordinator',
                phone: '021 000 0000',
                email: 'contact@example.test',
                is_primary: true,
            },
        ],
        can: {
            view_control_room: true,
            view_it_work: true,
            view_site_profile: true,
            export: false,
        },
    };
}
afterEach(cleanup);
beforeEach(() => {
    window.history.replaceState({}, '', '/security-devices/sites/4');
    navigation.visit.mockReset();
    navigation.actorId = 7;
});
function select(label: string) {
    fireEvent.click(screen.getByRole('tab', { name: label }));
}
describe('Site technology profile', () => {
    it('uses Home breadcrumbs and preserves the separately permitted Site-profile destination', () => {
        const data = fixture();
        const result = render(<SiteTechnologyShow {...data} />);
        expect(screen.getByRole('link', { name: 'Home' })).toHaveAttribute(
            'href',
            '/dashboard',
        );
        expect(
            screen.getByRole('link', { name: 'Open Site profile' }),
        ).toHaveAttribute('href', '/sites/4?tab=technology');
        result.rerender(
            <SiteTechnologyShow
                {...data}
                can={{ ...data.can, view_site_profile: false }}
            />,
        );
        expect(
            screen.queryByRole('link', { name: 'Open Site profile' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText('Site profile access required'),
        ).toBeInTheDocument();
    });
    it('honours legacy work anchors and never reveals a restricted alert or its target', () => {
        window.history.replaceState(
            {},
            '',
            '/security-devices/sites/4#site-work',
        );
        render(<SiteTechnologyShow {...fixture()} />);
        expect(
            screen.getByRole('link', { name: 'Available operational alert' }),
        ).toHaveAttribute('href', '/control-room/alerts/61');
        expect(
            screen.queryByText('Private alert detail'),
        ).not.toBeInTheDocument();
        expect(screen.queryByText('PRIVATE-62')).not.toBeInTheDocument();
        expect(
            document.querySelector('a[href="/control-room/alerts/62"]'),
        ).toBeNull();
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        expect(
            screen.queryByText('Private alert detail'),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'View evidence for Control Room alert access required',
            }),
        );
        expect(
            within(screen.getByRole('dialog')).getByText(
                'Control Room alert access required',
            ),
        ).toBeInTheDocument();
        expect(
            within(screen.getByRole('dialog')).queryByText(
                'Private alert detail',
            ),
        ).not.toBeInTheDocument();
    });
    it('keeps denied monitoring and work scopes distinct from empty results', () => {
        const data = fixture();
        data.monitoring.monitored_devices = null;
        data.summary.monitored_devices = null;
        data.summary.overdue_maintenance = null;
        data.can.view_it_work = false;
        data.can.view_control_room = false;
        render(<SiteTechnologyShow {...data} />);
        expect(
            screen.queryByText('Synthetic Site collector'),
        ).not.toBeInTheDocument();
        select('Monitoring');
        expect(screen.getByText(/No restricted readings/)).toBeInTheDocument();
        expect(
            screen.queryByText('Gateway reachability'),
        ).not.toBeInTheDocument();
        select('Alerts & work');
        expect(
            screen.getByText('Control Room permission is required.'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('IT & Support permission is required.'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Maintenance permission is required.'),
        ).toBeInTheDocument();
        expect(screen.queryByText('Open network work')).not.toBeInTheDocument();
        expect(
            screen.queryByText('Check switch power'),
        ).not.toBeInTheDocument();
    });
    it('filters failed checks using retained states and restores the complete check list', () => {
        render(<SiteTechnologyShow {...fixture()} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'View failed Site checks' }),
        );
        expect(
            screen.getByRole('link', { name: 'Gateway reachability' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Switch status' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Show all checks' }),
        );
        expect(
            screen.getByRole('link', { name: 'Switch status' }),
        ).toBeInTheDocument();
    });
    it('keeps device search through layout changes and clears it for the full-device meter', () => {
        render(<SiteTechnologyShow {...fixture()} />);
        fireEvent.change(
            screen.getByRole('searchbox', {
                name: 'Search devices at this Site',
            }),
            { target: { value: 'Core' } },
        );
        expect(
            screen.queryByRole('link', { name: 'Edge router' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        expect(
            screen.getByRole('searchbox', {
                name: 'Search devices at this Site',
            }),
        ).toHaveValue('Core');
        fireEvent.click(
            screen.getByRole('button', { name: 'View Site devices' }),
        );
        expect(
            screen.getByRole('searchbox', {
                name: 'Search devices at this Site',
            }),
        ).toHaveValue('');
        expect(
            screen.getByRole('link', { name: 'Edge router' }),
        ).toHaveAttribute('href', '/security-devices/devices/11');
    });
    it('does not turn WAN classification or incomplete topology into healthy-path evidence', () => {
        render(<SiteTechnologyShow {...fixture()} />);
        select('WAN & topology');
        expect(
            screen.getByText('WAN device classification'),
        ).toBeInTheDocument();
        expect(screen.getByText('Recorded')).toBeInTheDocument();
        expect(
            screen.getByText(/Unknown links are not treated as healthy paths/),
        ).toBeInTheDocument();
        expect(screen.queryByText('Healthy')).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Network group' }),
        ).toHaveAttribute('href', '/security-devices/device-groups/41');
    });
    it('opens a contact detail locally and removes it before painting a changed actor or Site scope', () => {
        const data = fixture();
        const result = render(<SiteTechnologyShow {...data} />);
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        expect(
            screen.queryByRole('link', { name: 'Synthetic contact' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'View evidence for Synthetic contact',
            }),
        );
        expect(
            within(screen.getByRole('dialog')).getByText(
                'contact@example.test',
            ),
        ).toBeInTheDocument();
        expect(navigation.visit).not.toHaveBeenCalled();
        navigation.actorId = 8;
        result.rerender(
            <SiteTechnologyShow
                {...data}
                site={{ ...data.site, id: 5, name: 'Synthetic other Site' }}
                contacts={[]}
            />,
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByText('contact@example.test'),
        ).not.toBeInTheDocument();
    });
    it('keeps IT, change and device owners and full next-action information available', () => {
        render(<SiteTechnologyShow {...fixture()} />);
        select('Alerts & work');
        expect(
            screen.getByRole('link', { name: 'Open network work' }),
        ).toHaveAttribute('href', '/it/tickets/71');
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        fireEvent.click(
            screen.getByRole('button', {
                name: 'View evidence for Open network work',
            }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: /^Next action and update/ }),
        );
        expect(
            within(screen.getByRole('dialog')).getByText(
                'Review the retained readings',
            ),
        ).toBeInTheDocument();
        fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
        select('Recent changes');
        expect(
            screen.getByRole('link', { name: 'Core switch' }),
        ).toHaveAttribute('href', '/security-devices/devices/12');
    });
    it('returns focus to the exact card when record names repeat', async () => {
        const data = fixture();
        data.contacts.push({
            ...data.contacts[0],
            id: 102,
            email: 'second@example.test',
        });
        render(<SiteTechnologyShow {...data} />);
        const cards = screen.getAllByRole('article', {
            name: 'Synthetic contact',
        });
        const trigger = within(cards[1]).getByRole('button', {
            name: 'Actions for Synthetic contact',
        });
        fireEvent.keyDown(trigger, { key: 'Enter' });
        fireEvent.click(
            await screen.findByRole('menuitem', { name: 'View evidence' }),
        );
        expect(
            within(screen.getByRole('dialog')).getByText('second@example.test'),
        ).toBeInTheDocument();
        fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
        await waitFor(() => expect(trigger).toHaveFocus());
    });
});
