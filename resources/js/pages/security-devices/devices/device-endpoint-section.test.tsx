import { router } from '@inertiajs/react';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    DeviceEndpointSection,
    endpointAgentBadgeMeta,
} from './device-endpoint-section';
import type { DeviceEndpointPosture } from './device-profile';
import { installationJSON } from './installation-profile';

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Link: ({
        href,
        children,
        preserveState: _preserveState,
        ...props
    }: {
        href: string;
        children: ReactNode;
        preserveState?: boolean;
    }) => {
        void _preserveState;
        return (
            <a href={href} {...props}>
                {children}
            </a>
        );
    },
}));

vi.mock('./installation-profile', async (importOriginal) => ({
    ...(await importOriginal<typeof import('./installation-profile')>()),
    installationJSON: vi.fn(),
}));

const basePosture = (): DeviceEndpointPosture => ({
    agent: {
        uuid: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        state: 'fresh',
        platform: 'windows',
        agentVersion: '1.2.0',
        enrolledAt: '2026-10-20T01:00:00Z',
        enrolmentExpiresAt: null,
        revokedAt: null,
        lastSeenAt: '2026-10-26T22:55:00Z',
        observedAt: '2026-10-26T22:55:00Z',
        sequence: 42,
        serviceActionsReady: true,
        restartActionsReady: true,
        softwareActionsReady: true,
        scriptExecutionAvailable: false,
        canRevoke: true,
        revokeHref: '/security-devices/devices/34/endpoint/revoke',
    },
    diagnosticsRestricted: false,
    inventory: {
        hostname: 'KAURI-RECEPTION-01',
        osVersion: 'Windows 11 Pro 24H2',
        architecture: 'amd64',
        memoryTotalBytes: 17179869184,
        memoryAvailableBytes: 8589934592,
        uptimeSeconds: 176400,
        cpuPercent: 18.4,
        disks: [
            {
                name: 'C:',
                totalBytes: 512110190592,
                freeBytes: 256055095296,
                usedBytes: 256055095296,
                usedPercent: 50,
            },
        ],
        services: [
            {
                name: 'Spooler',
                label: 'Print Spooler',
                state: 'running',
                approved: true,
                allowedOperations: ['start', 'stop', 'restart'],
                manageHref:
                    '/security-devices/devices/34?section=management&action=endpoint.service.manage&command_service_name=Spooler',
            },
            {
                name: 'wuauserv',
                label: 'Windows Update',
                state: 'stopped',
                approved: false,
                allowedOperations: [],
                manageHref: null,
            },
        ],
        servicesTotal: 2,
        servicesTruncated: false,
        servicesComplete: true,
        software: [
            {
                name: 'Oblivion Endpoint Agent',
                version: '1.2.0',
                publisher: 'Oblivion Findings',
            },
            {
                name: 'Microsoft Edge',
                version: '130.0.2849.56',
                publisher: 'Microsoft Corporation',
            },
        ],
        softwareTotal: 2,
        softwareTruncated: false,
        softwareComplete: true,
        probeErrors: [],
    },
    policies: {
        service: {
            available: true,
            version: 1,
            enabled: true,
            consentMode: 'attended',
            catalogueCount: 1,
            canManage: true,
        },
        restart: {
            available: true,
            version: 1,
            enabled: true,
            consentMode: 'attended',
            minimumWarningSeconds: 60,
            canManage: true,
        },
        software: {
            available: true,
            version: 1,
            enabled: false,
            consentMode: 'attended',
            canManage: true,
        },
        script: {
            available: false,
            unavailableReason: 'script_execution_not_implemented',
            version: 0,
            enabled: false,
            consentMode: 'attended',
            canManage: false,
        },
    },
    intune: {
        integrationEnabled: true,
        canManageLink: true,
        linkHref: '/security-devices/devices/34/endpoint/intune-link',
        linked: true,
        managedDeviceId: '11111111-2222-4333-8444-555555555555',
        syncStatus: 'current',
        syncedAt: '2026-10-26T22:00:00Z',
        lastAttemptAt: '2026-10-26T22:00:00Z',
        snapshot: {
            deviceName: 'KAURI-RECEPTION-01',
            operatingSystem: 'Windows',
            osVersion: '10.0.26100',
            complianceState: 'compliant',
            isEncrypted: true,
            managementAgent: 'mdm',
            managedDeviceOwnerType: 'company',
            manufacturer: 'Dell Inc.',
            model: 'OptiPlex 7010',
            serialNumber: 'SN-KAURI-01',
            enrolledDateTime: '2026-10-01T00:00:00Z',
            lastSyncDateTime: '2026-10-26T21:58:00Z',
        },
    },
    links: {
        remoteSupportHref: '/it/remote-support?device=34',
        approvedSoftwareHref: '/security-devices/software',
        agentDownloadsHref: '/security-devices/agent-downloads',
    },
});

beforeEach(() => {
    vi.clearAllMocks();
});

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

describe('DeviceEndpointSection', () => {
    it('maps every agent connection state to clear badge copy and variant', () => {
        expect(endpointAgentBadgeMeta('fresh')).toEqual({
            label: 'Agent connected',
            variant: 'success',
        });
        expect(endpointAgentBadgeMeta('stale')).toEqual({
            label: 'Heartbeat overdue',
            variant: 'warning',
        });
        expect(endpointAgentBadgeMeta('assignment_drift')).toEqual({
            label: 'Re-enrolment required',
            variant: 'critical',
        });
        expect(endpointAgentBadgeMeta('revoked')).toEqual({
            label: 'Agent revoked',
            variant: 'critical',
        });
        expect(endpointAgentBadgeMeta('not_enrolled')).toEqual({
            label: 'Not enrolled',
            variant: 'neutral',
        });
    });

    it('renders agent posture, searchable services and software, disabled script execution, and Intune posture', () => {
        render(
            <DeviceEndpointSection
                posture={basePosture()}
                deviceId={34}
                deviceName="Reception PC"
                canSetupAgent={true}
                onOpenInstallation={vi.fn()}
            />,
        );

        expect(screen.getByText('Agent connected')).toBeVisible();
        expect(screen.getAllByText('KAURI-RECEPTION-01').length).toBeGreaterThan(
            0,
        );
        expect(screen.getByText('18.4%')).toBeVisible();
        expect(
            screen.getAllByText('Script execution: Disabled').length,
        ).toBeGreaterThan(0);

        const servicesCard = screen.getByTestId('endpoint-services-card');
        expect(within(servicesCard).getByText('Print Spooler')).toBeVisible();
        expect(within(servicesCard).getByText('Windows Update')).toBeVisible();
        expect(
            within(servicesCard).getByRole('link', { name: 'Manage service' }),
        ).toHaveAttribute(
            'href',
            '/security-devices/devices/34?section=management&action=endpoint.service.manage&command_service_name=Spooler',
        );

        fireEvent.click(
            within(servicesCard).getByRole('button', { name: 'Approved' }),
        );
        expect(within(servicesCard).getByText('Print Spooler')).toBeVisible();
        expect(
            within(servicesCard).queryByText('Windows Update'),
        ).not.toBeInTheDocument();

        const softwareCard = screen.getByTestId('endpoint-software-card');
        fireEvent.change(
            within(softwareCard).getByLabelText('Filter installed software'),
            { target: { value: 'Edge' } },
        );
        expect(within(softwareCard).getByText('Microsoft Edge')).toBeVisible();
        expect(
            within(softwareCard).queryByText('Oblivion Endpoint Agent'),
        ).not.toBeInTheDocument();

        const intuneCard = screen.getByTestId('endpoint-intune-card');
        expect(
            within(intuneCard).getByText('Compliance: Compliant'),
        ).toBeVisible();
        expect(
            within(intuneCard).getByText('Encryption: Encrypted'),
        ).toBeVisible();
    });

    it('withholds diagnostic inventory when diagnosticsRestricted is true or assignment drifted', () => {
        const restricted: DeviceEndpointPosture = {
            ...basePosture(),
            diagnosticsRestricted: true,
            inventory: null,
        };

        const { rerender } = render(
            <DeviceEndpointSection
                posture={restricted}
                deviceId={34}
                deviceName="Reception PC"
                canSetupAgent={false}
            />,
        );

        expect(
            screen.getByText('Diagnostic inventory access required'),
        ).toBeVisible();
        expect(
            screen.queryByTestId('endpoint-services-card'),
        ).not.toBeInTheDocument();

        const drifted: DeviceEndpointPosture = {
            ...basePosture(),
            agent: {
                ...basePosture().agent,
                state: 'assignment_drift',
            },
            inventory: null,
        };

        rerender(
            <DeviceEndpointSection
                posture={drifted}
                deviceId={34}
                deviceName="Reception PC"
                canSetupAgent={true}
                onOpenInstallation={vi.fn()}
            />,
        );

        expect(screen.getByText('Re-enrolment required')).toBeVisible();
        expect(
            screen.getByText(
                /Previous diagnostic inventory is withheld until an administrator re-enrols the computer/i,
            ),
        ).toBeVisible();
    });

    it('validates Microsoft Intune UUID before posting link updates', () => {
        const postSpy = vi
            .spyOn(router, 'post')
            .mockImplementation(() => undefined as never);

        const unlinked: DeviceEndpointPosture = {
            ...basePosture(),
            intune: {
                ...basePosture().intune!,
                linked: false,
                managedDeviceId: null,
                syncStatus: null,
                snapshot: null,
            },
        };

        render(
            <DeviceEndpointSection
                posture={unlinked}
                deviceId={34}
                deviceName="Reception PC"
                canSetupAgent={false}
            />,
        );

        fireEvent.click(
            screen.getByRole('button', { name: /Link Intune device/i }),
        );

        const input = screen.getByLabelText('Managed device ID (UUID)');
        fireEvent.change(input, { target: { value: 'not-a-uuid' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save link' }));

        expect(
            screen.getByText(
                'Enter a valid Microsoft Intune managed device UUID.',
            ),
        ).toBeVisible();
        expect(postSpy).not.toHaveBeenCalled();

        fireEvent.change(input, {
            target: { value: '22222222-3333-4444-8555-666666666666' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save link' }));

        expect(postSpy).toHaveBeenCalledWith(
            '/security-devices/devices/34/endpoint/intune-link',
            {
                managed_device_id: '22222222-3333-4444-8555-666666666666',
                reason: null,
            },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('revokes computer agent through governed ReasonDialog and installationJSON', async () => {
        vi.mocked(installationJSON).mockResolvedValueOnce({ revoked: true });
        const reloadSpy = vi
            .spyOn(router, 'reload')
            .mockImplementation(() => undefined as never);

        render(
            <DeviceEndpointSection
                posture={basePosture()}
                deviceId={34}
                deviceName="Reception PC"
                canSetupAgent={false}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Revoke agent' }));
        fireEvent.change(screen.getByLabelText('Reason'), {
            target: {
                value: 'Computer was re-imaged after hardware repair.',
            },
        });
        fireEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'Revoke agent',
            }),
        );

        await waitFor(() => {
            expect(reloadSpy).toHaveBeenCalled();
        });
        expect(installationJSON).toHaveBeenCalledWith(
            34,
            'revoke',
            expect.any(AbortSignal),
            {
                reason: 'Computer was re-imaged after hardware repair.',
                expected_agent_uuid: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            },
        );
    });
});
