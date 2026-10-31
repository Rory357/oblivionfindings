import type { DeviceProfile } from '@/pages/security-devices/devices/device-profile';
import { DeviceHistorySection } from '@/pages/security-devices/devices/device-profile-sections';
import { DeviceTypeOverview } from '@/pages/security-devices/devices/device-type-overview';
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
    router: {
        post: vi.fn(),
        reload: vi.fn(),
    },
}));

vi.mock('@/components/leaflet-map', () => ({
    default: ({
        center,
    }: {
        center: { lat: number; lng: number };
    }) => (
        <div data-testid="mock-leaflet-map">
            Map {center.lat},{center.lng}
        </div>
    ),
}));

function baseProfile(overrides: Partial<DeviceProfile>): DeviceProfile {
    return {
        header: {
            identity: {
                id: 10,
                name: 'Test Device',
                uid: 'DEV-10',
                type: 'Device',
                category: 'network',
                manufacturer: 'Ubiquiti',
                model: 'UX7',
            },
            location: null,
            assignment: null,
            health: {
                state: 'healthy',
                label: 'Healthy',
                deviceState: 'active',
                deviceStateLabel: 'Active',
            },
            freshness: {
                state: 'fresh',
                observedAt: '2026-10-28T04:00:00Z',
                staleAfterSeconds: 900,
            },
            providerObservation: {
                provider: 'oblivion_native',
                label: 'Oblivion Native',
                observedAt: '2026-10-28T04:00:00Z',
                source: 'native_monitoring',
            },
            requiredAction: {
                state: 'none',
                label: 'No immediate action',
                description: 'All good.',
                section: 'health',
            },
        },
        sections: [
            { key: 'health', label: 'Health', group: 'status' },
            { key: 'monitors', label: 'Checks', group: 'status', count: 1 },
            { key: 'history', label: 'History', group: 'records', count: 2 },
        ],
        health: {
            state: 'healthy',
            deviceState: 'active',
            lastSeenAt: '2026-10-28T04:00:00Z',
            lastSignalAt: null,
            batteryLevel: null,
            batteryUpdatedAt: null,
            monitoring: {
                enabled: 1,
                healthy: 1,
                attention: 0,
                uncertain: 0,
            },
        },
        monitors: [],
        interfacesSensors: [],
        configuration: {
            registry: {
                manufacturer: 'Ubiquiti',
                model: 'UX7',
                serialNumber: null,
                assetTag: null,
                ipAddress: '172.16.0.1',
                macAddress: '70:a7:41:00:00:01',
                imei: null,
                commissionedAt: null,
                warrantyExpiresAt: null,
                nextServiceDue: null,
                expectedLifespanMonths: null,
                purchasePrice: null,
                notes: null,
                groups: [],
                createdBy: null,
                createdAt: null,
            },
            configuration: {
                state: 'aligned',
                observedHash: null,
                desiredHash: null,
                observedAt: null,
            },
            firmware: {
                state: 'not_observed',
                currentVersion: null,
                desiredVersion: null,
                observedAt: null,
            },
        },
        tickets: [],
        controlRoomAlerts: [],
        audit: [],
        management: {
            actorId: 1,
            visible: true,
            actions: [],
            history: [],
            canObserve: true,
            canApprove: true,
            stepUpCurrent: true,
            changeOptions: [],
            breakGlassReviewers: [],
            summary: {
                declared: 0,
                available: 0,
                awaitingApproval: 0,
                uncertain: 0,
                blocked: 0,
                breakGlassReviewDue: 0,
            },
        },
        capabilities: {
            registry: { supported: true, allowed: true, available: true, state: 'available' },
            assignment: { supported: true, allowed: true, available: true, state: 'available' },
            monitoring: { supported: true, allowed: true, available: true, state: 'available' },
            maintenance: { supported: true, allowed: true, available: true, state: 'available' },
            documents: { supported: true, allowed: true, available: true, state: 'available' },
            control: { supported: true, allowed: true, available: true, state: 'available', reason: 'Audited.' },
        },
        ...overrides,
    };
}

describe('DeviceTypeOverview & DeviceHistorySection', () => {
    it('renders tracker consent-gated message without coordinates when consent is missing, and map when available', () => {
        const gated = baseProfile({
            profileLayout: 'tracker',
            typeProfile: {
                kind: 'tracker',
                hasConsole: true,
                imeiMasked: '8679•••••••6998',
                position: {
                    state: 'consent_required',
                    message: 'Active resident location consent is required before coordinates can be shown.',
                    latitude: null,
                    longitude: null,
                    accuracy_m: null,
                    speed_kph: null,
                    heading_deg: null,
                    altitude_m: null,
                    battery_pct: null,
                    occurred_at: null,
                    address: null,
                    map_url: null,
                },
            },
        });

        const { unmount } = render(
            <DeviceTypeOverview
                profile={gated}
                deviceId={10}
                deviceName="Care Tracker"
                canSetupAgent={false}
                onOpenSection={() => undefined}
            />,
        );

        expect(
            screen.getByTestId('tracker-position-gated'),
        ).toHaveTextContent(
            'Active resident location consent is required before coordinates can be shown.',
        );
        expect(
            screen.queryByTestId('mock-leaflet-map'),
        ).not.toBeInTheDocument();
        unmount();

        const consented = baseProfile({
            profileLayout: 'tracker',
            typeProfile: {
                kind: 'tracker',
                hasConsole: true,
                imeiMasked: '8679•••••••6998',
                position: {
                    state: 'available',
                    message: 'Location permitted.',
                    latitude: -36.84846,
                    longitude: 174.76333,
                    accuracy_m: 8,
                    speed_kph: 0,
                    heading_deg: 0,
                    altitude_m: 20,
                    battery_pct: 91,
                    occurred_at: '2026-10-28T04:00:00Z',
                    address: null,
                    map_url: 'https://www.openstreetmap.org/?mlat=-36.84846&mlon=174.76333',
                },
            },
        });

        render(
            <DeviceTypeOverview
                profile={consented}
                deviceId={10}
                deviceName="Care Tracker"
                canSetupAgent={false}
                onOpenSection={() => undefined}
            />,
        );

        expect(screen.getByTestId('mock-leaflet-map')).toHaveTextContent(
            'Map -36.84846,174.76333',
        );
    });

    it('requires typing exact device name before submitting alarm disarm', () => {
        const alarmProfile = baseProfile({
            profileLayout: 'alarm_panel',
            typeProfile: {
                kind: 'alarm_panel',
                panelState: 'armed',
                panelStateAt: '2026-10-28T04:00:00Z',
                zones: [
                    {
                        zone: '1',
                        label: 'Front Foyer',
                        state: 'normal',
                        observedAt: '2026-10-28T04:00:00Z',
                    },
                ],
                commandStoreUrl: '/security-devices/devices/10/commands',
                armAction: null,
                disarmAction: {
                    key: 'alarm.disarm',
                    label: 'Disarm alarm',
                    risk: 'critical',
                    available: true,
                    allowed: true,
                    adapterAvailable: true,
                    state: 'available',
                    reason: 'Ready.',
                    confirmationMode: 'type_device_name',
                    requiresStepUp: true,
                    requiresMfa: true,
                    requiresApproval: true,
                    impact: 'Disarms physical intrusion protection.',
                    expectedResult: 'Panel disarmed.',
                },
            },
        });

        render(
            <DeviceTypeOverview
                profile={alarmProfile}
                deviceId={10}
                deviceName="Nikau Main Panel"
                canSetupAgent={false}
                onOpenSection={() => undefined}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Disarm alarm' }));

        const submitButtons = screen.getAllByRole('button', {
            name: 'Disarm alarm',
        });
        const confirmBtn = submitButtons[submitButtons.length - 1]!;
        expect(confirmBtn).toBeDisabled();

        fireEvent.change(
            screen.getByLabelText(/Operational reason/i),
            { target: { value: 'Authorised maintenance contractor arrival.' } },
        );
        fireEvent.click(screen.getByRole('checkbox'));
        expect(confirmBtn).toBeDisabled();

        fireEvent.change(
            screen.getByLabelText(/to confirm disarm/i),
            { target: { value: 'Nikau Main Panel' } },
        );
        expect(confirmBtn).not.toBeDisabled();
    });

    it('renders chronological history timeline and filters by entry kind', () => {
        const withHistory = baseProfile({
            historyTimeline: [
                {
                    id: 'event-1',
                    kind: 'event',
                    title: 'Door Forced',
                    detail: 'Access panel',
                    severity: 'critical',
                    actor: null,
                    occurredAt: '2026-10-28T04:05:00Z',
                },
                {
                    id: 'assign-1',
                    kind: 'assignment',
                    title: 'Assigned to Site',
                    detail: 'Permanent',
                    severity: 'info',
                    actor: null,
                    occurredAt: '2026-10-28T01:00:00Z',
                },
            ],
        });

        render(<DeviceHistorySection profile={withHistory} />);

        expect(screen.getByText('Door Forced')).toBeInTheDocument();
        expect(screen.getByText('Assigned to Site')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Events' }));
        expect(screen.getByText('Door Forced')).toBeInTheDocument();
        expect(screen.queryByText('Assigned to Site')).not.toBeInTheDocument();
    });
});
