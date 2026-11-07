import type { DevicePageMeter, DevicePageProps } from './_device-page-types';

/** Synthetic device pages for the vitest files (no real Sites or people). */

export const minutesAgo = (minutes: number) => new Date(Date.now() - minutes * 60_000).toISOString();

export function meter(overrides: Partial<DevicePageMeter> & Pick<DevicePageMeter, 'key' | 'label'>): DevicePageMeter {
    return {
        value: null,
        from: null,
        ago: null,
        caption: { text: 'Not read from this device', at: null },
        tone: 'brand',
        tab: 'overview',
        bar: null,
        ...overrides,
    };
}

export function pageFixture(overrides: Partial<DevicePageProps> = {}): DevicePageProps {
    return {
        device: {
            id: 41,
            name: 'Synthetic switch',
            kind: 'switch',
            kind_label: 'Network switch',
            group: 'network',
            state: 'online',
            badge: { label: 'Online', tone: 'success' },
            model: 'Synthetic SW-24',
            site: 'Synthetic House',
            site_id: 4,
            address: '10.0.0.2',
            watching: 'Online',
            subline: [
                { text: 'Synthetic SW-24', at: null },
                { text: 'Synthetic House', at: null },
                { text: '10.0.0.2', at: null },
                { text: 'checked {ago} by Synthetic hub', at: minutesAgo(1) },
            ],
            back: { href: '/security-devices/devices?tab=network', label: 'Devices' },
        },
        tab: 'overview',
        tabs: [
            { key: 'overview', label: 'Overview' },
            { key: 'checks', label: 'Checks & alerts' },
            { key: 'notes', label: 'Notes & documents' },
        ],
        range: '24h',
        meters: [
            meter({ key: 'reply', label: 'Reply time', value: '2 ms', caption: { text: 'At the check {ago}', at: minutesAgo(1) }, tone: 'success', tab: 'checks' }),
            meter({ key: 'uptime', label: 'Up for', from: minutesAgo(60 * 24 * 41), caption: { text: 'Last restart {time}', at: minutesAgo(60 * 24 * 41) } }),
            meter({ key: 'ports', label: 'Ports in use', value: '18 of 24', caption: { text: 'Read {ago}', at: minutesAgo(4) }, tab: 'checks' }),
            meter({ key: 'settings', label: 'Settings', value: 'No changes', caption: { text: 'Backed up {time}', at: minutesAgo(200) }, tone: 'success', tab: 'checks' }),
        ],
        can: { edit: true, watch: true, move: true, retire: true, assign: true, upload: true, see_events: true },
        actions: { ping: true, backup: null, remote_href: null },
        trouble: null,
        overview: {
            trend: {
                range: '24h',
                step_seconds: 3600,
                start: minutesAgo(60 * 24),
                points: [2, 2, 3, 2, 9, 3, 2, 2, 2, 2, 3, 2, 2, 4, 2, 2, 2, 2, 2, 2, 2, 3, 2, 2],
                unit: 'ms',
            },
            availability: { answered: 43_162, missed: 38, percent: 99.9, last_missed_at: minutesAgo(60 * 24 * 20), days: 30 },
            events: [
                { key: 'backup-9', at: minutesAgo(200), tone: 'success', title: 'Settings backed up', sub: 'No changes since the copy before', compare_id: null },
                { key: 'backup-8', at: minutesAgo(60 * 24 * 6), tone: 'info', title: 'Settings changed', sub: '2 lines changed since the copy before', compare_id: 8 },
            ],
            facts: [
                ['Model', 'Synthetic SW-24'],
                ['Address', '10.0.0.2'],
                ['Software', '6.6.61'],
            ],
            connected: {
                up: [{ id: 40, name: 'Synthetic gateway', kind: 'router', sub: 'Port 1', state: 'online', href: '/security-devices/devices/40' }],
                self: { id: 41, name: 'Synthetic switch', kind: 'switch', sub: 'This device', state: 'online', href: null },
                down: [{ id: 42, name: 'Synthetic Wi-Fi', kind: 'wifi_point', sub: 'Port 7', state: 'online', href: '/security-devices/devices/42' }],
                more: 0,
            },
        },
        health: null,
        remote: null,
        checks: null,
        notes: null,
        ...overrides,
    };
}
