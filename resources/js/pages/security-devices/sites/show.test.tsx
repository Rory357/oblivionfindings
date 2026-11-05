import { router } from '@inertiajs/react';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import SiteShow, { type SitePageProps } from './show';
import type { SiteDeviceListRow } from './_types';

function deviceRow(id: number, name: string, kind: SiteDeviceListRow['kind']): SiteDeviceListRow {
    return {
        id,
        name,
        sub: 'Synthetic model · 10.0.0.' + id,
        kind,
        kind_label: 'Device',
        state: 'online',
        site: 'Synthetic House',
        site_id: 4,
        address: '10.0.0.' + id,
        model: 'Synthetic model',
        battery: null,
        watching: 'Online',
        href: `/security-devices/devices/${id}`,
        badge: { label: 'Online', tone: 'success' },
        note: { text: 'Checked {ago}', at: new Date(Date.now() - 60_000).toISOString() },
        seen_at: new Date(Date.now() - 60_000).toISOString(),
        room: null,
        tab: 'network',
    };
}

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return {
        ...actual,
        Head: () => null,
        usePage: () => ({ props: { auth: { user: { id: 7 } } } }),
        router: { ...actual.router, get: vi.fn(), post: vi.fn(), delete: vi.fn(), visit: vi.fn(), on: () => () => undefined },
    };
});
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));

function fixture(overrides: Partial<SitePageProps> = {}): SitePageProps {
    return {
        site: { id: 4, name: 'Synthetic House', address: '1 Test Street, Testville' },
        tab: 'overview',
        flag: { tone: 'success', text: 'All online' },
        hub: { label: 'Synthetic House hub', own: true, answering: true, version: '1.0.0', seen_at: new Date().toISOString() },
        meters: {
            online: { count: 3, of: 3, caption: { text: 'Checked {ago}', at: new Date(Date.now() - 60_000).toISOString() } },
            alerts: { count: 0, caption: 'None open' },
            scan: null,
        },
        overview: {
            states: [{ state: 'online', count: 3 }],
            cameras: [{ id: 9, name: 'Synthetic front door camera', state: 'online', href: '/security-devices/devices/9' }],
            camera_count: 1,
            week: { values: [100, null, 99.2, 100, 100, 100, 100], labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], note: 'Lowest on Wed, when 99.2% of checks answered.' },
            hub_down: null,
        },
        network: null,
        internet: null,
        can: { add_device: true, assign: true, scan: true },
        ...overrides,
    };
}

afterEach(() => {
    cleanup();
});

describe('Site page', () => {
    it('shows the Site, how it is watched, and its overview, with the Rooms and Modes tabs (Phase F)', () => {
        render(<SiteShow {...fixture()} />);

        expect(screen.getByRole('heading', { name: 'Synthetic House' })).toBeInTheDocument();
        expect(screen.getByText(/its own hub is Synthetic House hub/)).toBeInTheDocument();
        expect(screen.getByText('Devices at Synthetic House')).toBeInTheDocument();
        expect(screen.getByText('Synthetic front door camera')).toBeInTheDocument();
        expect(screen.getByText('Lowest on Wed, when 99.2% of checks answered.')).toBeInTheDocument();
        expect(screen.getByRole('tab', { name: 'Rooms' })).toBeInTheDocument();
        expect(screen.getByRole('tab', { name: 'Modes' })).toBeInTheDocument();
        // Devices for everyone here; Alerts and Logbook only with their keys (12h, 12i).
        expect(screen.getByRole('tab', { name: /^devices/i })).toBeInTheDocument();
        expect(screen.queryByRole('tab', { name: /alerts|logbook/i })).not.toBeInTheDocument();
        // No modes and no switch permission: no Change mode.
        expect(screen.queryByText(/change mode/i)).not.toBeInTheDocument();
        expect(screen.getByText('No house modes yet')).toBeInTheDocument();
    });

    it('says plainly what still works when the hub stops answering', () => {
        render(
            <SiteShow
                {...fixture({
                    hub: { label: 'Synthetic House hub', own: true, answering: false, version: null, seen_at: new Date(Date.now() - 3_600_000).toISOString() },
                    overview: {
                        states: [{ state: 'cant_check', count: 2 }],
                        cameras: [],
                        camera_count: 0,
                        week: null,
                        hub_down: { hub: 'Synthetic House hub', seen_at: new Date(Date.now() - 3_600_000).toISOString(), cant_check: 2, still_seen: [] },
                    },
                })}
            />,
        );

        expect(screen.getByText('Synthetic House hub has stopped answering')).toBeInTheDocument();
        expect(screen.getByText(/2 devices it watches can’t be checked until it’s back/)).toBeInTheDocument();
        expect(screen.getByText('No cameras at this Site.')).toBeInTheDocument();
    });

    it('lists the Site’s devices with their room, problems first, not-watched ones in grey (12g)', () => {
        render(
            <SiteShow
                {...fixture({
                    tab: 'devices',
                    overview: null,
                    tabs: { devices: 2, alerts: true, logbook: true },
                    devices: {
                        rows: [
                            {
                                ...deviceRow(21, 'Synthetic yard camera', 'camera'),
                                state: 'offline',
                                badge: { label: 'Offline', tone: 'critical' },
                                note: { text: 'Since {time}', at: new Date(Date.now() - 600_000).toISOString() },
                                room: 'Back yard',
                                tab: 'cameras',
                            },
                            { ...deviceRow(22, 'Synthetic phone base', 'other'), state: 'not_watched', watching: 'Nothing', room: null, tab: 'other' },
                        ],
                        by_kind: { cameras: 1, other: 1 },
                        checked: [
                            { tone: 'info', text: '**Synthetic House hub** checks **1** of them from here' },
                            { tone: 'none', text: 'A device behind one that’s offline shows **Can’t check** in grey, not red' },
                        ],
                        not_watched: ['Synthetic phone base'],
                        not_watched_count: 1,
                        retired: [],
                        retired_count: 0,
                        can: { edit: true, watch: true, move: false, retire: false, export: false, commands: false },
                    },
                })}
            />,
        );

        expect(screen.getByRole('tab', { name: /alerts/i })).toBeInTheDocument();
        expect(screen.getByRole('tab', { name: /logbook/i })).toBeInTheDocument();
        expect(screen.getByText('Devices at Synthetic House')).toBeInTheDocument();
        expect(screen.getByText('Back yard')).toBeInTheDocument();
        expect(screen.getByRole('columnheader', { name: 'Room' })).toBeInTheDocument();
        expect(screen.queryByRole('columnheader', { name: 'Site' })).not.toBeInTheDocument();
        const rows = screen.getAllByRole('row');
        expect(rows.findIndex((row) => row.textContent?.includes('Synthetic yard camera'))).toBeLessThan(
            rows.findIndex((row) => row.textContent?.includes('Synthetic phone base')),
        );
        expect(screen.getByRole('button', { name: 'Select' })).toBeInTheDocument();
        expect(screen.getByText(/Synthetic phone base isn’t watched, so it’s last in this list, in grey/)).toBeInTheDocument();
        expect(screen.getByText('How they’re checked')).toBeInTheDocument();
        expect(screen.getByText('Synthetic House hub', { selector: 'b' })).toBeInTheDocument();
    });

    it('shows the Site’s alerts, who was told and how each ended, and that safety never pauses (12h)', () => {
        render(
            <SiteShow
                {...fixture({
                    tab: 'alerts',
                    overview: null,
                    tabs: { devices: 3, alerts: true, logbook: true },
                    alerts: {
                        rows: [
                            {
                                id: 5,
                                title: 'Synthetic yard camera has no picture',
                                sub: 'Check · Started failing',
                                device_id: 21,
                                device_kind: 'camera',
                                device: 'Synthetic yard camera',
                                state: 'snoozed',
                                at: new Date(Date.now() - 3_600_000).toISOString(),
                                told: 'Nobody was told',
                                ended: { badge: 'Snoozed', tone: 'neutral', note: 'Until 11:00 am · Synthetic Person: checking the port' },
                                href: '/security-devices/alerts?alert=5',
                            },
                        ],
                        days: 30,
                        told: [{ when: 'Safety alerts: panic button, alarm, water leak, door forced', then: 'Staff on shift here, in the app · Control Room has them too' }],
                        quiet: [],
                    },
                })}
            />,
        );

        expect(screen.getByText('Alerts at Synthetic House')).toBeInTheDocument();
        expect(screen.getByText('Synthetic yard camera has no picture')).toBeInTheDocument();
        expect(screen.getByText('Nobody was told')).toBeInTheDocument();
        expect(screen.getByText('Snoozed')).toBeInTheDocument();
        expect(screen.getByText('Until 11:00 am · Synthetic Person: checking the port')).toBeInTheDocument();
        expect(screen.getByText('Who’s told here')).toBeInTheDocument();
        expect(screen.getByText('Safety alerts never pause.')).toBeInTheDocument();
        expect(screen.getByText('No quiet time is planned.')).toBeInTheDocument();
        // Nothing is said about a 6:00 am summary: it isn't built.
        expect(screen.queryByText(/6:00 am summary/)).not.toBeInTheDocument();
    });

    it('keeps a plain logbook filtered by one What dropdown, not pill chips (12i)', () => {
        const at = new Date(Date.now() - 600_000).toISOString();
        render(
            <SiteShow
                {...fixture({
                    tab: 'logbook',
                    overview: null,
                    tabs: { devices: 3, alerts: true, logbook: true },
                    logbook: {
                        day: '2026-10-29',
                        today: true,
                        what: 'everything',
                        sections: [
                            {
                                key: 'day',
                                title: 'This morning',
                                items: [
                                    {
                                        key: 'mode-1',
                                        at,
                                        tone: 'info',
                                        kind: 'mode',
                                        title: 'House mode Night → Day',
                                        sub: 'By schedule',
                                        site_id: 4,
                                        site: 'Synthetic House',
                                        device_id: null,
                                        device_kind: null,
                                        href: '/security-devices/sites/4?tab=modes',
                                        next: null,
                                        by: null,
                                        action: null,
                                    },
                                ],
                            },
                            { key: 'night', title: 'Overnight and early morning', items: [] },
                            { key: 'before', title: 'Yesterday · Wed 28 Oct', items: [] },
                        ],
                    },
                })}
            />,
        );

        expect(screen.getByText('This morning')).toBeInTheDocument();
        expect(screen.getByText('House mode Night → Day')).toBeInTheDocument();
        expect(screen.getByLabelText('What')).toBeInTheDocument();
        expect(screen.getByText('What the logbook keeps')).toBeInTheDocument();
        expect(screen.queryByRole('tablist', { name: /what/i })).not.toBeInTheDocument();
        // Empty sections aren't drawn; Export the logbook asks for a reason (12i).
        expect(screen.queryByText('Overnight and early morning')).not.toBeInTheDocument();
        expect(screen.getByText('Export the logbook')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Export…' })).toBeInTheDocument();
    });

    it('exports the logbook only with a reason, sending the Site, date and format (12i)', async () => {
        const postSpy = vi.spyOn(axios, 'post').mockResolvedValue({
            data: new Blob(['%PDF-1.4'], { type: 'application/pdf' }),
            headers: { 'content-disposition': 'attachment; filename="security-devices-logbook-synthetic-house-2026-10-29.pdf"' },
        });
        render(
            <SiteShow
                {...fixture({
                    tab: 'logbook',
                    overview: null,
                    tabs: { devices: 3, alerts: true, logbook: true },
                    logbook: {
                        day: '2026-10-29',
                        today: true,
                        what: 'alerts',
                        sections: [
                            { key: 'day', title: 'This morning', items: [] },
                            { key: 'night', title: 'Overnight and early morning', items: [] },
                            { key: 'before', title: 'Yesterday · Wed 28 Oct', items: [] },
                        ],
                    },
                })}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Export…' }));
        expect(screen.getByRole('dialog')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Export PDF' }));
        expect(screen.getByText('Give a short reason for exporting the logbook.')).toBeInTheDocument();
        expect(postSpy).not.toHaveBeenCalled();

        fireEvent.change(screen.getByLabelText('Reason'), { target: { value: 'Shift handover review' } });
        fireEvent.click(screen.getByRole('button', { name: 'Export PDF' }));

        await waitFor(() => {
            expect(postSpy).toHaveBeenCalledWith(
                '/security-devices/sites/4/logbook/export',
                { format: 'pdf', from: '2026-10-29', to: '2026-10-29', what: 'alerts', reason: 'Shift handover review' },
                { responseType: 'blob' },
            );
        });
        postSpy.mockRestore();
    });

    it('lists the links found beside the map, with the configured Can’t check line (12j)', () => {
        render(
            <SiteShow
                {...fixture({
                    tab: 'network',
                    overview: null,
                    network: {
                        tree: {
                            tier_counts: { gateways: 1, switches: 0, access_points: 0, devices: 0 },
                            sources: [],
                            last_captured_at: null,
                            mapped_count: 1,
                            roots: [
                                {
                                    id: 1,
                                    device_uid: 'synthetic-gw',
                                    name: 'Synthetic gateway',
                                    tier: 'gateway',
                                    domain: 'it_infrastructure',
                                    category: 'network',
                                    subcategory: 'router',
                                    manufacturer: null,
                                    model: null,
                                    ip_address: '10.0.0.1',
                                    mac_address: null,
                                    status: 'online',
                                    health: 'online',
                                    href: '/security-devices/devices/1',
                                    uplink: null,
                                    children: [],
                                },
                            ],
                            unplaced: [],
                        },
                        links: {
                            nothing: false,
                            sources: [{ key: 'hub', who: 'Synthetic House hub', text: 'asks the gateway and switch what’s plugged into each port', at: new Date(Date.now() - 600_000).toISOString() }],
                            threshold: 80,
                            rows: [
                                {
                                    key: '1-2',
                                    name: 'Synthetic gateway → Synthetic switch',
                                    sub: 'Cable',
                                    parent_id: 1,
                                    child_id: 2,
                                    child_kind: 'switch',
                                    child_href: '/security-devices/devices/2',
                                    by: 'Synthetic House hub',
                                    ports: 'LAN 1 → Port 1',
                                    sure: 98,
                                    seen_at: new Date(Date.now() - 600_000).toISOString(),
                                    map: { state: 'new', tone: 'info', label: 'Not on the map yet', note: 'First found {time}', at: new Date(Date.now() - 600_000).toISOString() },
                                    relationship: null,
                                },
                            ],
                            changes: [],
                            unplaced: { count: 1, names: ['Synthetic leak sensor'], more: 0 },
                            can: { change: true },
                        },
                    },
                })}
            />,
        );

        expect(screen.getByText('Where the links come from')).toBeInTheDocument();
        expect(screen.getByText('Links that Oblivion is at least 80% sure of also decide which devices show Can’t check when the one before them goes offline.')).toBeInTheDocument();
        expect(screen.getByText('Links found')).toBeInTheDocument();
        expect(screen.getByText('Not on the map yet')).toBeInTheDocument();
        expect(screen.getByText('98%')).toBeInTheDocument();
        expect(screen.getByText(/Nothing reports a link for Synthetic leak sensor/)).toBeInTheDocument();
        expect(screen.getByText('The links people have added or confirmed')).toBeInTheDocument();

        fireEvent.contextMenu(screen.getByText('LAN 1 → Port 1'));
        expect(screen.getByRole('menuitem', { name: 'Add to the map' })).toBeInTheDocument();
        expect(screen.queryByRole('menuitem', { name: 'Take off the map' })).not.toBeInTheDocument();
    });

    it('says when nothing reports links yet, with Add a hub', () => {
        render(
            <SiteShow
                {...fixture({
                    tab: 'network',
                    overview: null,
                    hub: null,
                    can: { add_device: true, assign: true, scan: true, add_hub: true },
                    network: {
                        tree: { tier_counts: { gateways: 0, switches: 0, access_points: 0, devices: 1 }, sources: [], last_captured_at: null, mapped_count: 0, roots: [], unplaced: [] },
                        links: { nothing: true, sources: [], threshold: 85, rows: [], changes: [], unplaced: { count: 0, names: [], more: 0 }, can: { change: false } },
                    },
                })}
            />,
        );

        expect(screen.getByText('Nothing reports links here yet.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Add a hub' })).toBeInTheDocument();
    });

    it('lets device editors place unplaced radio devices on the map by hand and take manual links off (12j)', () => {
        vi.mocked(router.post).mockImplementationOnce((_url, _data, options) => {
            options?.onSuccess?.({} as never);
            options?.onFinish?.({} as never);
        });

        render(
            <SiteShow
                {...fixture({
                    tab: 'network',
                    overview: null,
                    network: {
                        tree: { tier_counts: { gateways: 1, switches: 0, access_points: 0, devices: 3 }, sources: [], last_captured_at: null, mapped_count: 2, roots: [], unplaced: [] },
                        links: {
                            nothing: false,
                            sources: [],
                            threshold: 85,
                            rows: [
                                {
                                    key: '1-10',
                                    name: 'Synthetic sensor gateway → Existing leak sensor',
                                    sub: 'Radio',
                                    parent_id: 1,
                                    child_id: 10,
                                    child_kind: 'sensor',
                                    child_href: '/security-devices/devices/10',
                                    by: 'Placed by hand',
                                    ports: 'Radio',
                                    sure: null,
                                    seen_at: null,
                                    map: { state: 'on', tone: 'success', label: 'On the map', note: 'Placed by hand · {date}', at: '2026-10-29T01:00:00Z' },
                                    relationship: { id: 77, device_id: 1 },
                                },
                            ],
                            changes: [],
                            unplaced: {
                                count: 2,
                                names: ['Kitchen water leak', 'Staff panic button'],
                                more: 0,
                                items: [
                                    { id: 11, name: 'Kitchen water leak', kind: 'sensor', sub: 'Sensor' },
                                    { id: 12, name: 'Staff panic button', kind: 'sensor', sub: 'Sensor' },
                                ],
                                parents: [
                                    { id: 1, name: 'Synthetic sensor gateway', kind: 'sensor_gateway', sub: 'Sensor gateway' },
                                    { id: 2, name: 'Synthetic gateway', kind: 'router', sub: 'Router' },
                                    { id: 11, name: 'Kitchen water leak', kind: 'sensor', sub: 'Sensor' },
                                    { id: 12, name: 'Staff panic button', kind: 'sensor', sub: 'Sensor' },
                                ],
                            },
                            can: { change: true },
                        },
                    },
                })}
            />,
        );

        const placeButton = screen.getByRole('button', { name: 'Place them (2)' });
        expect(placeButton).toBeInTheDocument();
        fireEvent.click(placeButton);

        expect(screen.getByTestId('sd-place-on-map')).toBeInTheDocument();
        expect(screen.getByText('Synthetic sensor gateway · Sensor gateway')).toBeInTheDocument();
        fireEvent.change(screen.getByLabelText(/Note/), { target: { value: 'Talks by radio' } });
        fireEvent.click(screen.getByRole('button', { name: 'Place 2 on the map' }));

        expect(router.post).toHaveBeenCalledWith(
            '/security-devices/sites/4/network/place',
            {
                parent_device_id: 1,
                child_device_ids: [11, 12],
                port: 'Radio',
                notes: 'Talks by radio',
            },
            expect.any(Object),
        );

        // Manually placed rows show Take off the map and ask for a reason.
        fireEvent.contextMenu(screen.getByText('Placed by hand'));
        fireEvent.click(screen.getByRole('menuitem', { name: 'Take off the map' }));
        expect(screen.getByTestId('sd-take-link-off')).toBeInTheDocument();
        expect(screen.getByText('The map stops drawing it. The device goes back to Not placed yet, and you can place it again.')).toBeInTheDocument();
        fireEvent.change(screen.getByLabelText(/Why it comes off/), { target: { value: 'Sensor retired' } });
        fireEvent.click(screen.getByRole('button', { name: 'Take off the map' }));
        expect(router.delete).toHaveBeenCalledWith(
            '/security-devices/devices/1/relationships/77',
            expect.objectContaining({
                data: { reason: 'Sensor retired' },
            }),
        );
    });

    it('is honest about what it doesn’t know about the internet line', () => {
        render(<SiteShow {...fixture({ tab: 'internet', overview: null, internet: { gateways: [], outages: [] } })} />);

        expect(screen.getByText('No router or gateway is added for this Site yet.')).toBeInTheDocument();
        expect(screen.getByText('Which internet line the Site uses and whether it has a backup line.')).toBeInTheDocument();
    });
});
