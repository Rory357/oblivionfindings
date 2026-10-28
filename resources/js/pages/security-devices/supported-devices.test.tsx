import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SupportedDevicesPage, {
    type SupportedDevicesProps,
} from './supported-devices';

const mocks = vi.hoisted(() => ({
    actor: 7,
    can: {} as Record<string, Record<string, boolean>>,
}));

vi.mock('@inertiajs/react', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/react')>(
            '@inertiajs/react',
        );
    return {
        ...actual,
        Head: () => null,
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
        usePage: () => ({
            url: '/security-devices/supported-devices',
            props: {
                auth: {
                    user: mocks.actor ? { id: mocks.actor } : null,
                    can: mocks.can,
                },
            },
        }),
    };
});

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));

function fixture(): SupportedDevicesProps {
    return {
        actor_id: 7,
        generated_at: '2026-10-28T09:00:00Z',
        summary: {
            total_drivers: 3,
            brands_count: 3,
            tested_on_hardware: 1,
            built_from_docs: 1,
            planned: 1,
        },
        classes: [
            { key: 'camera', label: 'Cameras & recorders', count: 1 },
            { key: 'alarm', label: 'Alarm panels & receivers', count: 2 },
        ],
        support_levels: [
            {
                key: 'tested_on_hardware',
                label: 'Tested on real hardware',
                badge_variant: 'success',
                count: 1,
            },
            {
                key: 'built_from_docs',
                label: 'Built from vendor docs',
                badge_variant: 'info',
                count: 1,
            },
            {
                key: 'planned',
                label: 'Planned',
                badge_variant: 'neutral',
                count: 1,
            },
        ],
        drivers: [
            {
                key: 'camera.onvif_standard',
                brand: 'Standard ONVIF & Tapo',
                name: 'ONVIF Profile S & T Camera',
                device_class: 'camera',
                device_class_label: 'Cameras & recorders',
                support_level: 'tested_on_hardware',
                support_level_label: 'Tested on real hardware',
                support_badge_variant: 'success',
                transport_summary: 'ONVIF SOAP 1.2 + RTSP (Ports 80 / 2020 / 554)',
                models: ['TP-Link Tapo C120', 'ONVIF Profile S & T cameras'],
                capabilities: [
                    {
                        key: 'camera_snapshot',
                        label: 'Snapshot capture',
                        requires_confirmation: false,
                    },
                    {
                        key: 'camera_ptz',
                        label: 'Pan, tilt & zoom',
                        requires_confirmation: false,
                    },
                ],
                summary:
                    'Connects to ONVIF Profile S and Profile T cameras on the local site network.',
                limitations_note: null,
                hardware_evidence:
                    'Verified against TP-Link Tapo C120 on 172.16.0.78:2020.',
                official_docs: [
                    {
                        label: 'ONVIF Profile T Specification',
                        url: 'https://www.onvif.org/profiles/profile-t/',
                    },
                ],
                setup_href: '/security-devices/discovery',
            },
            {
                key: 'alarm.gallagher_command_centre',
                brand: 'Gallagher Security',
                name: 'Command Centre Local REST',
                device_class: 'alarm',
                device_class_label: 'Alarm panels & receivers',
                support_level: 'built_from_docs',
                support_level_label: 'Built from vendor docs',
                support_badge_variant: 'info',
                transport_summary: 'Local HTTPS REST API (GGL-API-KEY + mTLS)',
                models: ['Command Centre v8.60+'],
                capabilities: [
                    {
                        key: 'alarm_arm',
                        label: 'Arm area',
                        requires_confirmation: true,
                    },
                    {
                        key: 'alarm_disarm',
                        label: 'Disarm area',
                        requires_confirmation: true,
                    },
                ],
                summary:
                    'Queries alarm zone state and executes confirmed arm or disarm actions.',
                limitations_note:
                    'Requires a local client certificate issued by the site Command Centre server.',
                hardware_evidence: null,
                official_docs: [
                    {
                        label: 'Gallagher REST API Docs',
                        url: 'https://gallaghersecurity.github.io/',
                    },
                ],
                setup_href: null,
            },
            {
                key: 'alarm.arrowhead_elite',
                brand: 'Arrowhead Alarm Products',
                name: 'AAP Elite S / ESL-2 Native IP',
                device_class: 'alarm',
                device_class_label: 'Alarm panels & receivers',
                support_level: 'planned',
                support_level_label: 'Planned',
                support_badge_variant: 'neutral',
                transport_summary: 'AAP IP module (SIA DC-09 active today)',
                models: ['ESL-2', 'Elite S'],
                capabilities: [
                    {
                        key: 'alarm_events',
                        label: 'Alarm & zone events',
                        requires_confirmation: false,
                    },
                ],
                summary:
                    'Native arm and disarm requires AAP installer documentation; use SIA DC-09 reporting today.',
                limitations_note:
                    'Direct panel control is gated on vendor integrator documentation.',
                hardware_evidence: null,
                official_docs: [
                    {
                        label: 'Arrowhead Alarm Products NZ',
                        url: 'https://www.aap.co.nz/',
                    },
                ],
                setup_href: null,
            },
        ],
    };
}

beforeEach(() => {
    mocks.actor = 7;
    mocks.can = {
        securityDevices: {
            viewAny: true,
            integrationsView: true,
            integrationsManage: true,
            monitoringManage: true,
            commandsAdmin: true,
        },
    };
});

afterEach(() => {
    cleanup();
});

describe('SupportedDevicesPage', () => {
    it('renders honest support badges, confirmation indicators, hardware notes, and official doc links without jargon', () => {
        const { container } = render(<SupportedDevicesPage {...fixture()} />);

        expect(
            screen.getAllByText('Supported devices').length,
        ).toBeGreaterThanOrEqual(1);
        expect(
            screen.getAllByText('Tested on real hardware').length,
        ).toBeGreaterThanOrEqual(1);
        expect(
            screen.getAllByText('Built from vendor docs').length,
        ).toBeGreaterThanOrEqual(1);
        expect(screen.getAllByText('Planned').length).toBeGreaterThanOrEqual(1);

        expect(
            screen.getByText(
                'Verified against TP-Link Tapo C120 on 172.16.0.78:2020.',
            ),
        ).toBeTruthy();
        expect(screen.getByText('Disarm area')).toBeTruthy();

        const docLink = screen.getByRole('link', {
            name: /Gallagher REST API Docs/i,
        });
        expect(docLink.getAttribute('href')).toBe(
            'https://gallaghersecurity.github.io/',
        );

        const text = (container.textContent ?? '').toLocaleLowerCase();
        for (const forbidden of [
            'canonical',
            'governed',
            'provenance',
            'evidence-backed',
            'projection',
        ]) {
            expect(text).not.toContain(forbidden);
        }
    });

    it('filters drivers by search text, device class, and verification status and resets cleanly', () => {
        render(<SupportedDevicesPage {...fixture()} />);

        const searchInput = screen.getByPlaceholderText(
            /Search brand, model, or protocol/i,
        );
        fireEvent.change(searchInput, { target: { value: 'Arrowhead' } });

        expect(screen.getByText('AAP Elite S / ESL-2 Native IP')).toBeTruthy();
        expect(screen.queryByText('ONVIF Profile S & T Camera')).toBeNull();
        expect(screen.queryByText('Command Centre Local REST')).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: /Clear filters/i }));

        expect(screen.getByText('ONVIF Profile S & T Camera')).toBeTruthy();
        expect(screen.getByText('Command Centre Local REST')).toBeTruthy();
        expect(screen.getByText('AAP Elite S / ESL-2 Native IP')).toBeTruthy();
    });
});
