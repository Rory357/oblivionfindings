import { SiteTopologyTreeCard } from '@/components/security-devices/site-topology-tree';
import type { SiteTopologyTree } from '@/pages/security-devices/sites/_types';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        className,
    }: {
        href: string;
        children: React.ReactNode;
        className?: string;
    }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

const sampleTree: SiteTopologyTree = {
    tier_counts: {
        gateways: 1,
        switches: 1,
        access_points: 1,
        devices: 2,
    },
    sources: ['lldp', 'unifi'],
    last_captured_at: '2026-10-28T04:00:00Z',
    mapped_count: 4,
    roots: [
        {
            id: 1,
            device_uid: 'DEV-GW-1',
            name: 'Harbour UX7 Gateway',
            tier: 'gateway',
            domain: 'it_infrastructure',
            category: 'network',
            subcategory: 'gateway',
            manufacturer: 'Ubiquiti',
            model: 'UX7',
            ip_address: '172.16.0.1',
            mac_address: '70:a7:41:00:00:01',
            status: 'active',
            health: 'healthy',
            href: '/security-devices/devices/1',
            uplink: null,
            children: [
                {
                    id: 2,
                    device_uid: 'DEV-SW-1',
                    name: 'Core PoE Switch',
                    tier: 'switch',
                    domain: 'it_infrastructure',
                    category: 'network',
                    subcategory: 'switch',
                    manufacturer: 'Ubiquiti',
                    model: 'USW-24-PoE',
                    ip_address: '172.16.0.2',
                    mac_address: '24:5a:4c:00:00:02',
                    status: 'active',
                    health: 'healthy',
                    href: '/security-devices/devices/2',
                    uplink: {
                        protocol: 'lldp',
                        label: 'LLDP · lan0 -> Gi1/0/1',
                        port: 'lan0 -> Gi1/0/1',
                    },
                    children: [
                        {
                            id: 3,
                            device_uid: 'DEV-AP-1',
                            name: 'Lounge Wi-Fi AP',
                            tier: 'access_point',
                            domain: 'it_infrastructure',
                            category: 'network',
                            subcategory: 'access_point',
                            manufacturer: 'Ubiquiti',
                            model: 'U6-Pro',
                            ip_address: '172.16.0.3',
                            mac_address: '24:5a:4c:00:00:03',
                            status: 'active',
                            health: 'healthy',
                            href: '/security-devices/devices/3',
                            uplink: {
                                protocol: 'unifi',
                                label: 'UniFi · Port 4',
                                port: 'Port 4',
                            },
                            children: [
                                {
                                    id: 4,
                                    device_uid: 'DEV-CAM-1',
                                    name: 'Courtyard Camera',
                                    tier: 'device',
                                    domain: 'security',
                                    category: 'cctv',
                                    subcategory: 'ip_camera',
                                    manufacturer: 'Hikvision',
                                    model: 'DS-2CD2143',
                                    ip_address: '172.16.0.61',
                                    mac_address: '44:19:b6:00:00:61',
                                    status: 'active',
                                    health: 'healthy',
                                    href: '/security-devices/devices/4',
                                    uplink: {
                                        protocol: 'unifi',
                                        label: 'UniFi · Wi-Fi (Harbour-IoT)',
                                        port: 'Wi-Fi (Harbour-IoT)',
                                    },
                                    children: [],
                                },
                            ],
                        },
                    ],
                },
            ],
        },
    ],
    unplaced: [
        {
            id: 5,
            device_uid: 'DEV-SNS-1',
            name: 'Hallway Leak Sensor',
            tier: 'device',
            domain: 'facilities',
            category: 'leak_detection',
            subcategory: 'water_leak',
            manufacturer: 'Milesight',
            model: 'WS303',
            ip_address: null,
            mac_address: null,
            status: 'active',
            health: 'healthy',
            href: '/security-devices/devices/5',
            uplink: null,
            children: [],
        },
    ],
};

describe('SiteTopologyTreeCard', () => {
    it('renders gateway -> switch -> access point -> device hierarchy with profile links and collapse controls', () => {
        render(
            <SiteTopologyTreeCard tree={sampleTree} siteName="Harbour Care" />,
        );

        expect(
            screen.getByText('Automatic Site network map'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Harbour UX7 Gateway' }),
        ).toHaveAttribute('href', '/security-devices/devices/1');
        expect(
            screen.getByRole('link', { name: 'Core PoE Switch' }),
        ).toHaveAttribute('href', '/security-devices/devices/2');
        expect(
            screen.getByRole('link', { name: 'Lounge Wi-Fi AP' }),
        ).toHaveAttribute('href', '/security-devices/devices/3');
        expect(
            screen.getByRole('link', { name: 'Courtyard Camera' }),
        ).toHaveAttribute('href', '/security-devices/devices/4');
        expect(
            screen.getByText('LLDP · lan0 -> Gi1/0/1'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Hallway Leak Sensor'),
        ).toBeInTheDocument();

        // Collapse all hides nested branches beneath root
        fireEvent.click(screen.getByRole('button', { name: 'Collapse all' }));
        expect(
            screen.queryByRole('link', { name: 'Courtyard Camera' }),
        ).not.toBeInTheDocument();

        // Expand all restores them
        fireEvent.click(screen.getByRole('button', { name: 'Expand all' }));
        expect(
            screen.getByRole('link', { name: 'Courtyard Camera' }),
        ).toBeInTheDocument();
    });
});
