import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import SetupPage from './index';
import type { SetupProps } from './_types';

const routerGet = vi.fn();
vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return {
        ...actual,
        Head: () => null,
        usePage: () => ({ props: { auth: { user: { id: 7 } } } }),
        router: { ...actual.router, get: (...args: unknown[]) => routerGet(...args), visit: vi.fn(), reload: vi.fn(), on: () => () => undefined },
    };
});
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));

const sections: SetupProps['sections'] = [
    { key: 'overview', label: 'Overview', tabs: [] },
    { key: 'hubs', label: 'Hubs & networks', tabs: [{ key: 'networks', label: 'Networks & scans' }] },
    {
        key: 'services',
        label: 'Services & logins',
        tabs: [
            { key: 'connected', label: 'Connected services' },
            { key: 'logins', label: 'Saved logins' },
            { key: 'supported', label: 'Supported devices' },
        ],
    },
    { key: 'history', label: 'Change history', tabs: [] },
];

function fixture(overrides: Partial<SetupProps> = {}): SetupProps {
    return {
        section: 'overview',
        tab: null,
        sections,
        meters: [
            { key: 'todo', label: 'Still to set up', value: '1', caption: 'Synthetic House has no hub yet', tone: 'warning', section: 'overview', tab: null },
            { key: 'hubs', label: 'Hubs', value: '1 of 1', caption: 'Online · on 1.3.1', tone: 'success', section: 'hubs', tab: 'networks' },
            { key: 'services', label: 'Connected services', value: '1', caption: 'Of 4 accounts', tone: null, section: 'services', tab: 'connected' },
            { key: 'logins', label: 'Saved logins', value: '1', caption: '1 failed', tone: 'critical', section: 'services', tab: 'logins' },
            { key: 'computers', label: 'Computers with Obli', value: '0', caption: 'None yet', tone: null, section: null, tab: null, href: '/security-devices/devices?tab=computers' },
            { key: 'doors', label: 'Doors', value: '0', caption: 'None yet', tone: null, section: null, tab: null, href: '/security-devices/devices?tab=alarms_doors' },
        ],
        can: { manage: true, add_network: true, add_hub: true, approve_hubs: true, export_history: true, open_devices: true },
        overview: {
            todo: [
                {
                    key: 'no-hub-4',
                    tone: 'warning',
                    text: '**Synthetic House has no hub yet.** Its devices can’t be checked until one is set up.',
                    action: { kind: 'add_hub', label: 'Add a hub', site_id: 4 },
                },
            ],
            cards: [
                {
                    key: 'hubs',
                    icon: 'router',
                    title: 'Hubs & networks',
                    tone: 'warning',
                    lines: [{ tone: 'success', text: '**1 hub online**, on 1.3.1', at: null }],
                    link: { label: 'Review hubs & networks', section: 'hubs', tab: 'networks' },
                },
            ],
        },
        settings: null,
        alerts: null,
        checks: null,
        broker: null,
        networks: null,
        connected: null,
        logins: null,
        supported: null,
        history: null,
        ...overrides,
    };
}

afterEach(() => {
    cleanup();
    routerGet.mockReset();
});

describe('Setup', () => {
    it('opens on what is still to set up and a review card per section, in plain words', () => {
        render(<SetupPage {...fixture()} />);

        expect(screen.getByRole('heading', { name: 'Setup' })).toBeInTheDocument();
        const todo = screen.getByLabelText('Still to set up');
        expect(within(todo).getByText('Synthetic House has no hub yet.')).toBeInTheDocument();
        expect(within(todo).getByRole('button', { name: 'Add a hub' })).toBeInTheDocument();
        expect(screen.getByText('Hubs & networks', { selector: 'div' })).toBeInTheDocument();
        expect(screen.queryByText(/collector|observation|canonical/i)).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Review hubs & networks/ }));
        expect(routerGet).toHaveBeenCalledWith('/security-devices/setup/hubs?tab=networks', {}, expect.anything());
    });

    it('lists networks and marks a switch that loosens a safety check when it is reviewed', () => {
        render(
            <SetupPage
                {...fixture({
                    section: 'hubs',
                    tab: 'networks',
                    overview: null,
                    settings: {
                        'scans.daily': { type: 'bool', on: true, default: true, changed: false, loosens_when_on: false, loosens_when_off: false, can_change: true },
                        'scans.server_direct': { type: 'bool', on: false, default: false, changed: false, loosens_when_on: true, loosens_when_off: false, can_change: true },
                    },
                    networks: {
                        rows: [
                            {
                                id: 3,
                                site: { id: 4, name: 'Synthetic House' },
                                range: '10.20.0.0/24',
                                checked_by: 'Synthetic House hub',
                                hub_id: 9,
                                last: { text: 'Couldn’t finish · {time}', at: new Date().toISOString(), tone: 'critical' },
                                next: { text: 'When asked', at: null },
                                found: null,
                                run_id: 12,
                                can_scan: true,
                                scan_blocked: null,
                            },
                        ],
                        notes: [],
                    },
                })}
            />,
        );

        expect(screen.getByText('10.20.0.0/24')).toBeInTheDocument();
        expect(screen.getByText('Synthetic House hub')).toBeInTheDocument();
        expect(screen.getByText(/Couldn’t finish ·/)).toBeInTheDocument();
        expect(screen.getByText('No unsaved changes')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('switch', { name: 'Let the Oblivion server scan networks it can reach directly' }));
        expect(screen.getByText('1 change not saved')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Review changes' }));
        expect(screen.getByText('This loosens a safety check.')).toBeInTheDocument();
        expect(screen.getByLabelText('Why loosen it?')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Save, including the loosened check' })).toBeInTheDocument();
    });

    it('shows saved logins with their state and never anything secret, and a switch the viewer cannot change is locked', () => {
        render(
            <SetupPage
                {...fixture({
                    section: 'services',
                    tab: 'logins',
                    overview: null,
                    can: { manage: false, add_network: false, add_hub: false, approve_hubs: false, export_history: false, open_devices: false },
                    logins: {
                        rows: [
                            {
                                key: 'login-1',
                                used_for: 'MikroTik login',
                                sub: 'Device management',
                                icon: 'key',
                                site: { id: 4, name: 'Synthetic House' },
                                used_by: 'Synthetic House hub',
                                last_used: null,
                                state: { tone: 'critical', text: 'Login failed' },
                            },
                        ],
                        kept: [['Stored', 'Encrypted in the Oblivion database']],
                    },
                })}
            />,
        );

        expect(screen.getByText('MikroTik login')).toBeInTheDocument();
        expect(screen.getByText('Login failed')).toBeInTheDocument();
        expect(screen.getByText('Not used yet')).toBeInTheDocument();
        expect(screen.getByText('Encrypted in the Oblivion database')).toBeInTheDocument();
        expect(screen.getByRole('tab', { name: /Saved logins/ })).toBeInTheDocument();
        // No save bar for someone who can't change anything.
        expect(screen.queryByText('No unsaved changes')).not.toBeInTheDocument();
    });

    it('sets who is told when a device stops answering, and how', () => {
        render(
            <SetupPage
                {...fixture({
                    section: 'alerts',
                    tab: 'rules',
                    overview: null,
                    sections: [...sections.slice(0, 3), { key: 'alerts', label: 'Alerts & checks', tabs: [{ key: 'rules', label: 'Alert rules' }] }, sections[3]],
                    alerts: { rules: 0 },
                    settings: {
                        'alerts.devices.role': { type: 'role', value: null, default: null, changed: false, can_change: true, options: [{ value: null, label: 'IT for that Site' }, { value: 'it_admin', label: 'IT admin' }] },
                        'alerts.devices.app': { type: 'bool', on: true, default: true, changed: false, loosens_when_on: false, loosens_when_off: true, can_change: true },
                        'alerts.devices.email': { type: 'bool', on: true, default: true, changed: false, loosens_when_on: false, loosens_when_off: false, can_change: true },
                        'alerts.devices.push': { type: 'bool', on: false, default: false, changed: false, loosens_when_on: false, loosens_when_off: false, can_change: true },
                        'notify.new_devices.app': { type: 'bool', on: true, default: true, changed: false, loosens_when_on: false, loosens_when_off: false, can_change: true },
                        'notify.new_devices.email': { type: 'bool', on: false, default: false, changed: false, loosens_when_on: false, loosens_when_off: false, can_change: true },
                        'notify.new_devices.push': { type: 'bool', on: false, default: false, changed: false, loosens_when_on: false, loosens_when_off: false, can_change: true },
                    },
                })}
            />,
        );

        expect(screen.getByText('Who is told when a rule doesn’t say')).toBeInTheDocument();
        expect(screen.getByText('No rules of your own yet, so these defaults apply everywhere.')).toBeInTheDocument();
        // Turning off the app notice for devices not answering loosens a safety check.
        fireEvent.click(screen.getByRole('switch', { name: 'Devices and hubs not answering: In-app' }));
        fireEvent.click(screen.getByRole('button', { name: 'Review changes' }));
        expect(screen.getByText('Save 1 change to alert rules?')).toBeInTheDocument();
        expect(screen.getByText('This loosens a safety check.')).toBeInTheDocument();
    });

    it('shows the 6:00 am daily summary channels and overnight quiet hours without claiming printer toner', () => {
        render(
            <SetupPage
                {...fixture({
                    section: 'alerts',
                    tab: 'rules',
                    overview: null,
                    sections: [...sections.slice(0, 3), { key: 'alerts', label: 'Alerts & checks', tabs: [{ key: 'rules', label: 'Alert rules' }] }, sections[3]],
                    alerts: { rules: 2 },
                    settings: {
                        'alerts.devices.role': { type: 'role', value: null, default: null, changed: false, can_change: true, options: [{ value: null, label: 'IT for that Site' }] },
                        'alerts.devices.app': { type: 'bool', on: true, default: true, changed: false, loosens_when_on: false, loosens_when_off: true, can_change: true },
                        'alerts.devices.email': { type: 'bool', on: true, default: true, changed: false, loosens_when_on: false, loosens_when_off: false, can_change: true },
                        'alerts.devices.push': { type: 'bool', on: false, default: false, changed: false, loosens_when_on: false, loosens_when_off: false, can_change: true },
                        'notify.new_devices.app': { type: 'bool', on: true, default: true, changed: false, loosens_when_on: false, loosens_when_off: false, can_change: true },
                        'notify.new_devices.email': { type: 'bool', on: false, default: false, changed: false, loosens_when_on: false, loosens_when_off: false, can_change: true },
                        'notify.new_devices.push': { type: 'bool', on: false, default: false, changed: false, loosens_when_on: false, loosens_when_off: false, can_change: true },
                        'alerts.summary.app': { type: 'bool', on: true, default: true, changed: false, loosens_when_on: false, loosens_when_off: false, can_change: true },
                        'alerts.summary.email': { type: 'bool', on: true, default: true, changed: false, loosens_when_on: false, loosens_when_off: false, can_change: true },
                        'alerts.summary.push': { type: 'bool', on: false, default: false, changed: false, loosens_when_on: false, loosens_when_off: false, can_change: true },
                        'alerts.quiet_hours.on': { type: 'bool', on: true, default: true, changed: false, loosens_when_on: false, loosens_when_off: false, can_change: true },
                        'alerts.quiet_hours.start': { type: 'time', value: '22:00', default: '22:00', changed: false, can_change: true },
                        'alerts.quiet_hours.end': { type: 'time', value: '06:00', default: '06:00', changed: false, can_change: true },
                    },
                })}
            />,
        );

        expect(screen.getByText('Low batteries')).toBeInTheDocument();
        expect(screen.getByText('One summary at 6:00 am')).toBeInTheDocument();
        expect(screen.queryByText(/toner/i)).not.toBeInTheDocument();
        expect(screen.getByText('Daily summary')).toBeInTheDocument();
        expect(screen.getByRole('switch', { name: 'Daily summary: Email' })).toBeChecked();
        expect(screen.getByRole('switch', { name: 'Hold device alerts overnight' })).toBeChecked();
        // Stephan, 6 Nov: a router, gateway or hub that stops answering still gets through.
        expect(screen.getByText(/a router, gateway or hub that stops answering still tells IT on call straight away/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Quiet hours start/ })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Quiet hours end/ })).toBeInTheDocument();

        fireEvent.click(screen.getByRole('switch', { name: 'Hold device alerts overnight' }));
        expect(screen.getByText('1 change not saved')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Review changes' }));
        expect(screen.getByText('Save 1 change to alert rules?')).toBeInTheDocument();
        expect(screen.queryByText('This loosens a safety check.')).not.toBeInTheDocument();
    });

    it('lists the change history with export and a pager', () => {
        render(
            <SetupPage
                {...fixture({
                    section: 'history',
                    overview: null,
                    history: {
                        rows: [{ id: 1, at: new Date().toISOString(), who: 'Synthetic Admin', what: 'Turned daily network scans off', where: 'Every Site', site_id: null }],
                        page: 1,
                        pages: 2,
                        total: 26,
                        kept: [['Settings changes', '2 years (your audit policy)']],
                    },
                })}
            />,
        );

        expect(screen.getByText('Turned daily network scans off')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /Export/ })).toHaveAttribute('href', '/security-devices/setup/history/export');
        expect(screen.getByText('2 years (your audit policy)')).toBeInTheDocument();
    });
});
