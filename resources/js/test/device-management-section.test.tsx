import {
    catalogue,
    release,
} from '@/pages/security-devices/software/software-fixtures';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import axios from 'axios';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { DeviceProfile } from '@/pages/security-devices/devices/device-profile';
import { DeviceManagementSection } from '@/pages/security-devices/devices/device-profile-sections';

vi.mock('axios', () => ({ default: { get: vi.fn() } }));

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    router: {
        post: inertia.post,
    },
}));

const action = {
    key: 'access.door.unlock_timed',
    label: 'Unlock door temporarily',
    domain: 'access_control',
    workspace: 'security',
    sensitivity: 'security_control',
    group: 'high_risk_control' as const,
    level: 'control' as const,
    risk: 'high' as const,
    impact: 'Temporarily unlocks this exact door for the approved attendance window.',
    expectedResult:
        'The provider confirms the door returns to locked after the bounded window.',
    confirmationMode: 'acknowledge_impact' as const,
    executionMode: 'central_runtime' as const,
    executionGuidance:
        'The main Oblivion Findings runtime can reach this Device over the approved Site network path.',
    allowed: true,
    adapterAvailable: true,
    available: true,
    state: 'available',
    reason: 'Governed request available.',
    requiresStepUp: true,
    requiresMfa: false,
    requiresFreshObservation: true,
    freshness: {
        state: 'fresh',
        observedAt: '2026-07-24T01:00:00Z',
        staleAfterSeconds: 300,
    },
    requiresApproval: true,
    requiresChange: false,
    allowsBreakGlass: true,
    expiresAfterSeconds: 300,
    parameters: [
        {
            name: 'duration_seconds',
            label: 'Duration Seconds',
            type: 'integer' as const,
            min: 5,
            max: 60,
            options: [],
        },
    ],
};

const serviceAction: DeviceProfile['management']['actions'][number] = {
    ...action,
    key: 'endpoint.service.manage',
    label: 'Manage Windows service',
    domain: 'network_it',
    executionMode: 'endpoint_runtime',
    allowsBreakGlass: false,
    parameters: [
        {
            name: 'service_name',
            label: 'Service',
            type: 'string',
            min: null,
            max: null,
            options: ['Spooler'],
        },
        {
            name: 'operation',
            label: 'Operation',
            type: 'string',
            min: null,
            max: null,
            options: ['restart'],
        },
    ],
};

const blockedAction = {
    ...action,
    key: 'access.door.lockdown',
    label: 'Start lockdown',
    risk: 'critical' as const,
    impact: 'Places this exact door into lockdown and may materially affect life safety and access.',
    expectedResult:
        'A fresh provider observation confirms locked and lockdown state.',
    confirmationMode: 'type_device_name' as const,
    requiresMfa: true,
    expiresAfterSeconds: 120,
    adapterAvailable: false,
    available: false,
    state: 'provider_adapter_required',
    reason: 'The provider has not registered an approved execution adapter.',
};

const changeAction = {
    ...action,
    key: 'device.reboot',
    label: 'Restart device',
    domain: 'network_it',
    group: 'high_risk_control' as const,
    level: 'control' as const,
    risk: 'high' as const,
    requiresApproval: true,
    requiresChange: true,
    parameters: [],
};

function profile(
    management: Partial<DeviceProfile['management']> = {},
): DeviceProfile {
    return {
        header: {
            identity: {
                id: 42,
                name: 'Harbour service door',
                uid: 'DOOR-42',
                type: 'Door controller',
                manufacturer: 'Ubiquiti',
                model: 'UA-Hub',
            },
            location: {
                id: 9,
                type: 'site',
                name: 'Harbour Site',
                href: '/sites/9',
            },
            assignment: null,
            health: {
                state: 'healthy',
                label: 'Healthy',
                deviceState: 'online',
                deviceStateLabel: 'Online',
            },
            freshness: {
                state: 'fresh',
                observedAt: '2026-07-24T01:00:00Z',
                staleAfterSeconds: 300,
            },
            providerObservation: {
                provider: 'unifi',
                label: 'UniFi Access',
                observedAt: '2026-07-24T01:00:00Z',
                source: 'provider',
            },
            requiredAction: {
                state: 'healthy',
                label: 'No action required',
                description: 'The Device is operating normally.',
                section: 'health',
            },
        },
        management: {
            visible: true,
            actions: [action, blockedAction],
            history: [],
            canObserve: true,
            canApprove: true,
            stepUpCurrent: false,
            changeOptions: [],
            breakGlassReviewers: [],
            summary: {
                declared: 2,
                available: 1,
                awaitingApproval: 0,
                uncertain: 0,
                blocked: 0,
                breakGlassReviewDue: 0,
            },
            ...management,
        },
    } as DeviceProfile;
}

describe('Device Management section', () => {
    it('requires an explicit approved-software choice even when a URL supplies a package UUID', async () => {
        const softwareAction = {
            ...serviceAction,
            key: 'endpoint.software.install',
            label: 'Install approved software',
            parameters: [
                {
                    name: 'package_uuid',
                    label: 'Software release',
                    type: 'string' as const,
                    min: null,
                    max: null,
                    options: [],
                },
            ],
        };
        vi.mocked(axios.get).mockResolvedValue({ data: catalogue(42) });
        window.history.replaceState(
            {},
            '',
            '/security-devices/devices/42?action=endpoint.software.install&command_package_uuid=' +
                release().uuid,
        );
        render(
            <DeviceManagementSection
                deviceId={42}
                profile={profile({
                    actorId: 9001,
                    actions: [softwareAction],
                    stepUpCurrent: true,
                })}
            />,
        );
        await screen.findByRole('button', { name: 'Choose' });
        expect(
            screen.queryByLabelText('Software release'),
        ).not.toBeInTheDocument();
        fireEvent.change(screen.getByLabelText('Operational reason'), {
            target: { value: 'Install the verified viewer on this computer.' },
        });
        fireEvent.click(
            screen.getByRole('checkbox', { name: /I understand this impact/ }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Create request' }));
        expect(inertia.post).not.toHaveBeenCalled();
        expect(
            screen.getByText(
                /Choose an approved software release before requesting/,
            ),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Choose' }));
        fireEvent.click(screen.getByRole('button', { name: 'Create request' }));
        expect(inertia.post).toHaveBeenCalledWith(
            '/security-devices/devices/42/commands',
            expect.objectContaining({
                capability: 'endpoint.software.install',
                expected_actor_id: 9001,
                parameters: { package_uuid: release().uuid },
                impact_acknowledged: true,
            }),
            expect.anything(),
        );
        const first = inertia.post.mock.calls[0];
        act(() => first[2].onFinish());
        expect(screen.getByRole('button', { name: 'Selected' })).toBeDisabled();
        expect(
            screen.getByRole('textbox', { name: 'Search approved software' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry same request' }),
        );
        expect(inertia.post.mock.calls[1][1]).toEqual(first[1]);
    });
    it('does not send a software request without the current actor', async () => {
        const softwareAction = {
            ...serviceAction,
            key: 'endpoint.software.install',
            label: 'Install approved software',
            parameters: [
                {
                    name: 'package_uuid',
                    label: 'Software release',
                    type: 'string' as const,
                    min: null,
                    max: null,
                    options: [],
                },
            ],
        };
        render(
            <DeviceManagementSection
                deviceId={42}
                profile={profile({
                    actions: [softwareAction],
                    stepUpCurrent: true,
                })}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Request action' }));
        await screen.findByText(/Approved software could not be loaded/);
        fireEvent.change(screen.getByLabelText('Operational reason'), {
            target: { value: 'Install the verified viewer on this computer.' },
        });
        fireEvent.click(
            screen.getByRole('checkbox', { name: /I understand this impact/ }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Create request' }));
        expect(inertia.post).not.toHaveBeenCalled();
        expect(
            screen.getByText(/Your account could not be confirmed/),
        ).toBeInTheDocument();
    });

    it('retains an immutable command when its acknowledgement is lost', () => {
        render(
            <DeviceManagementSection
                deviceId={42}
                profile={profile({
                    actorId: 9001,
                    actions: [serviceAction],
                    stepUpCurrent: true,
                })}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Request action' }));
        fireEvent.change(screen.getByLabelText('Operational reason'), {
            target: { value: 'Restore the approved service for staff.' },
        });
        fireEvent.click(
            screen.getByRole('checkbox', { name: /I understand this impact/ }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Create request' }));
        const first = inertia.post.mock.calls[0];
        act(() => first[2].onFinish());
        expect(screen.getByLabelText('Operational reason')).toBeDisabled();
        expect(
            screen.getByText(/No confirmation was received/),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(screen.getByRole('button', { name: 'Request action' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry same request' }),
        );
        const second = inertia.post.mock.calls[1];
        expect(second[0]).toBe(first[0]);
        expect(second[1]).toEqual(first[1]);
        expect(second[1].expected_actor_id).toBe(9001);
        expect(second[1].parameters).toEqual({
            service_name: 'Spooler',
            operation: 'restart',
        });
        act(() => {
            second[2].onSuccess();
            second[2].onFinish();
        });
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('requires a confirmed actor before requesting a native service action', () => {
        render(
            <DeviceManagementSection
                deviceId={42}
                profile={profile({
                    actions: [serviceAction],
                    stepUpCurrent: true,
                })}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Request action' }));
        fireEvent.change(screen.getByLabelText('Operational reason'), {
            target: { value: 'Restore printing for this computer.' },
        });
        fireEvent.click(
            screen.getByRole('checkbox', { name: /I understand this impact/ }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Create request' }));
        expect(inertia.post).not.toHaveBeenCalled();
        expect(
            screen.getByText(/Your account could not be confirmed/),
        ).toBeInTheDocument();
    });

    it('clears a native request draft when the signed-in actor changes', () => {
        const { rerender } = render(
            <DeviceManagementSection
                deviceId={42}
                profile={profile({
                    actorId: 9001,
                    actions: [serviceAction],
                    stepUpCurrent: true,
                })}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Request action' }));
        fireEvent.change(screen.getByLabelText('Operational reason'), {
            target: { value: 'Private purpose from the previous technician.' },
        });
        rerender(
            <DeviceManagementSection
                deviceId={42}
                profile={profile({
                    actorId: 9002,
                    actions: [serviceAction],
                    stepUpCurrent: true,
                })}
            />,
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByDisplayValue(
                'Private purpose from the previous technician.',
            ),
        ).not.toBeInTheDocument();
        expect(inertia.post).not.toHaveBeenCalled();
    });

    beforeEach(() => {
        inertia.post.mockReset();
        window.history.replaceState({}, '', '/security-devices/devices/42');
    });

    it('opens the exact governed action supplied by a cross-module deep link', () => {
        const locationRefresh = {
            ...action,
            key: 'tracking.location_refresh',
            label: 'Refresh current location',
            domain: 'tracking',
            workspace: 'tracking',
            sensitivity: 'location_privacy',
            impact: 'Requests one fresh location observation for this exact tracker.',
            expectedResult:
                'Fresh privacy-permitted tracker telemetry is received and reconciled.',
            requiresApproval: false,
            parameters: [],
        };
        window.history.replaceState(
            {},
            '',
            '/security-devices/devices/42?section=management&action=tracking.location_refresh',
        );

        render(
            <DeviceManagementSection
                deviceId={42}
                profile={profile({
                    actions: [locationRefresh],
                    stepUpCurrent: true,
                })}
            />,
        );

        const dialog = screen.getByRole('dialog');
        expect(
            within(dialog).getByText('Refresh current location'),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText(/one fresh location observation/i),
        ).toBeInTheDocument();
    });

    it('prefills an allowlisted configuration section from a governed handoff', () => {
        const configurationRefresh = {
            ...action,
            key: 'configuration.refresh',
            label: 'Refresh configuration snapshot',
            domain: 'tracking',
            workspace: 'tracking',
            sensitivity: 'standard',
            level: 'manage' as const,
            risk: 'medium' as const,
            requiresApproval: false,
            parameters: [
                {
                    name: 'section',
                    label: 'Section',
                    type: 'string' as const,
                    min: null,
                    max: null,
                    options: ['all', 'SRI', 'CFG'],
                },
            ],
        };
        window.history.replaceState(
            {},
            '',
            '/security-devices/devices/42?section=management&action=configuration.refresh&command_section=SRI',
        );

        render(
            <DeviceManagementSection
                deviceId={42}
                profile={profile({
                    actions: [configurationRefresh],
                    stepUpCurrent: true,
                })}
            />,
        );

        expect(screen.getByRole('combobox', { name: 'Section' })).toHaveValue(
            'SRI',
        );
    });

    it('prefills the exact compatible profile and submits its numeric identity through governed management', () => {
        const configurationApply = {
            ...changeAction,
            key: 'configuration.apply',
            label: 'Apply configuration',
            parameters: [
                {
                    name: 'configuration_profile_id',
                    label: 'Configuration Profile',
                    type: 'integer' as const,
                    min: 1,
                    max: null,
                    options: ['73'],
                    optionLabels: { '73': 'Resident safety · v2' },
                },
            ],
        };
        window.history.replaceState(
            {},
            '',
            '/security-devices/devices/42?section=management&action=configuration.apply&command_configuration_profile_id=73',
        );

        render(
            <DeviceManagementSection
                deviceId={42}
                profile={profile({
                    actions: [configurationApply],
                    stepUpCurrent: true,
                    changeOptions: [
                        {
                            id: 71,
                            reference: 'IT-000071',
                            title: 'Tracker configuration rollout',
                            workflowState: 'scheduled',
                            maintenanceEndsAt: '2026-07-24T02:00:00Z',
                        },
                    ],
                })}
            />,
        );

        expect(
            screen.getByRole('combobox', { name: 'Configuration Profile' }),
        ).toHaveValue('73');
        expect(screen.getByText('Resident safety · v2')).toBeInTheDocument();
        fireEvent.change(screen.getByLabelText('Operational reason'), {
            target: {
                value: 'Apply the approved resident tracker configuration profile.',
            },
        });
        fireEvent.click(screen.getByLabelText(/I understand this impact/i));
        fireEvent.click(screen.getByRole('button', { name: 'Create request' }));

        expect(inertia.post).toHaveBeenCalledWith(
            '/security-devices/devices/42/commands',
            expect.objectContaining({
                capability: 'configuration.apply',
                parameters: { configuration_profile_id: 73 },
                it_change_id: 71,
            }),
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('keeps a deep-linked action behind identity confirmation when step-up is not current', () => {
        const locationRefresh = {
            ...action,
            key: 'tracking.location_refresh',
            label: 'Refresh current location',
            domain: 'tracking',
            workspace: 'tracking',
            sensitivity: 'location_privacy',
            requiresApproval: false,
            parameters: [],
        };
        window.history.replaceState(
            {},
            '',
            '/security-devices/devices/42?section=management&action=tracking.location_refresh',
        );

        render(
            <DeviceManagementSection
                deviceId={42}
                profile={profile({
                    actions: [locationRefresh],
                    stepUpCurrent: false,
                })}
            />,
        );

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Confirm identity first' }),
        ).toHaveAttribute(
            'href',
            '/security-devices/devices/42/commands/confirm-identity',
        );
    });

    it('explains the remote collector route before a governed request is created', () => {
        render(
            <DeviceManagementSection
                deviceId={42}
                profile={profile({
                    actions: [
                        {
                            ...action,
                            executionMode: 'collector_runtime',
                            executionGuidance:
                                'This remote-only Device will use its current Site-scoped collector and encrypted ordered result path.',
                        },
                    ],
                    stepUpCurrent: true,
                })}
            />,
        );

        expect(
            screen.getByText('Execution · Remote Site collector'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                /remote-only Device will use its current Site-scoped collector/i,
            ),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Request action' }));
        expect(
            within(screen.getByRole('dialog')).getByText(
                'Remote Site collector',
            ),
        ).toBeInTheDocument();
    });

    it('uses a non-revealing empty state when no action is available in the current context', () => {
        render(
            <DeviceManagementSection
                profile={profile({
                    actions: [],
                    summary: {
                        declared: 0,
                        available: 0,
                        awaitingApproval: 0,
                        uncertain: 0,
                        blocked: 0,
                        breakGlassReviewDue: 0,
                    },
                })}
                deviceId={42}
            />,
        );

        expect(
            screen.getByRole('heading', {
                name: 'No management actions available',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'No management action is available for your current access and Device context. Monitoring remains available.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Unlock door temporarily'),
        ).not.toBeInTheDocument();
    });

    it('shows exact governance requirements and never enables an unsupported provider action', () => {
        render(<DeviceManagementSection profile={profile()} deviceId={42} />);

        expect(screen.getByText('Device actions')).toBeInTheDocument();
        expect(screen.getAllByText('Independent approval')).toHaveLength(2);
        expect(screen.getAllByText('Fresh-state reconciliation')).toHaveLength(
            2,
        );
        expect(
            screen.getByRole('link', { name: 'Confirm identity first' }),
        ).toHaveAttribute(
            'href',
            '/security-devices/devices/42/commands/confirm-identity',
        );
        expect(screen.getByText('Harbour service door')).toBeInTheDocument();
        expect(screen.getByText('Harbour Site')).toBeInTheDocument();
        expect(screen.getByText('UniFi Access')).toBeInTheDocument();
        expect(
            screen.getByText(
                /provider has not registered an approved execution adapter/i,
            ),
        ).toBeInTheDocument();

        const requestButtons = screen.getAllByRole('button', {
            name: 'Request action',
        });
        expect(requestButtons).toHaveLength(1);
        expect(requestButtons[0]).toBeDisabled();
    });

    it('posts only the server-declared capability and parameter names with a meaningful reason', () => {
        render(
            <DeviceManagementSection
                profile={profile({ stepUpCurrent: true })}
                deviceId={42}
            />,
        );

        fireEvent.click(
            screen.getAllByRole('button', { name: 'Request action' })[0],
        );
        fireEvent.change(screen.getByLabelText('Duration Seconds'), {
            target: { value: '20' },
        });
        fireEvent.change(screen.getByLabelText('Operational reason'), {
            target: {
                value: 'Allow the verified technician through the service door.',
            },
        });
        fireEvent.click(screen.getByLabelText(/I understand this impact/i));
        fireEvent.click(screen.getByRole('button', { name: 'Create request' }));

        expect(inertia.post).toHaveBeenCalledWith(
            '/security-devices/devices/42/commands',
            expect.objectContaining({
                capability: 'access.door.unlock_timed',
                parameters: { duration_seconds: 20 },
                reason: 'Allow the verified technician through the service door.',
                idempotency_key: expect.stringMatching(/^command_42_/),
                impact_acknowledged: true,
            }),
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('submits only a server-provided current IT Change for a change-governed action', () => {
        render(
            <DeviceManagementSection
                deviceId={42}
                profile={profile({
                    actions: [changeAction],
                    stepUpCurrent: true,
                    changeOptions: [
                        {
                            id: 71,
                            reference: 'IT-000071',
                            title: 'Gateway maintenance',
                            workflowState: 'scheduled',
                            maintenanceEndsAt: '2026-07-24T02:00:00Z',
                        },
                    ],
                })}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Request action' }));
        expect(screen.getByLabelText('Approved IT Change')).toHaveValue('71');
        fireEvent.change(screen.getByLabelText('Operational reason'), {
            target: {
                value: 'Restart the gateway in the approved maintenance window.',
            },
        });
        fireEvent.click(screen.getByLabelText(/I understand this impact/i));
        fireEvent.click(screen.getByRole('button', { name: 'Create request' }));

        expect(inertia.post).toHaveBeenCalledWith(
            '/security-devices/devices/42/commands',
            expect.objectContaining({
                capability: 'device.reboot',
                parameters: {},
                it_change_id: 71,
            }),
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('requires explicit impact acknowledgement and the exact Device name for a critical action', () => {
        const criticalAction = {
            ...blockedAction,
            adapterAvailable: true,
            available: true,
            state: 'available',
            reason: 'Governed request available.',
        };
        render(
            <DeviceManagementSection
                deviceId={42}
                profile={profile({
                    actions: [criticalAction],
                    stepUpCurrent: true,
                })}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Request action' }));
        const dialog = screen.getByRole('dialog');
        expect(
            within(dialog).getByText(/materially affect life safety/i),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText(/fresh provider observation confirms/i),
        ).toBeInTheDocument();
        expect(within(dialog).getByText(/Configured MFA/)).toBeInTheDocument();
        expect(
            within(dialog).getByText('Oblivion central runtime'),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByRole('button', { name: 'Create request' }),
        ).toBeDisabled();

        fireEvent.click(
            within(dialog).getByLabelText(/I understand this impact/i),
        );
        fireEvent.change(
            within(dialog).getByLabelText(/Type.*Harbour service door/i),
            { target: { value: 'Wrong door' } },
        );
        fireEvent.change(within(dialog).getByLabelText('Operational reason'), {
            target: {
                value: 'Initiate the independently approved emergency lockdown procedure.',
            },
        });
        expect(
            within(dialog).getByRole('button', { name: 'Create request' }),
        ).toBeDisabled();
        fireEvent.change(
            within(dialog).getByLabelText(/Type.*Harbour service door/i),
            { target: { value: 'Harbour service door' } },
        );
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Create request' }),
        );

        expect(inertia.post).toHaveBeenCalledWith(
            '/security-devices/devices/42/commands',
            expect.objectContaining({
                capability: 'access.door.lockdown',
                impact_acknowledged: true,
                confirmation_text: 'Harbour service door',
            }),
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('requires a designated reviewer and emergency declaration before posting break glass', () => {
        render(
            <DeviceManagementSection
                profile={profile({
                    stepUpCurrent: true,
                    breakGlassReviewers: [
                        { id: 73, name: 'Independent IT reviewer' },
                    ],
                })}
                deviceId={42}
            />,
        );

        fireEvent.click(
            screen.getAllByRole('button', { name: 'Request action' })[0],
        );
        fireEvent.click(
            screen.getByLabelText(/declare emergency break glass/i),
        );
        expect(
            screen.getByLabelText('Designated post-use reviewer'),
        ).toHaveValue('73');
        fireEvent.change(screen.getByLabelText('Duration Seconds'), {
            target: { value: '15' },
        });
        fireEvent.change(screen.getByLabelText('Operational reason'), {
            target: {
                value: 'Permit the verified response technician through the service entrance.',
            },
        });
        fireEvent.change(screen.getByLabelText('Emergency declaration'), {
            target: {
                value: 'A person is trapped and emergency access is required immediately.',
            },
        });
        fireEvent.click(screen.getByLabelText(/I understand this impact/i));
        fireEvent.click(screen.getByRole('button', { name: 'Create request' }));

        expect(inertia.post).toHaveBeenCalledWith(
            '/security-devices/devices/42/commands',
            expect.objectContaining({
                capability: 'access.door.unlock_timed',
                break_glass: true,
                break_glass_reason:
                    'A person is trapped and emergency access is required immediately.',
                break_glass_reviewer_user_id: 73,
            }),
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('shows a due break-glass review and submits one permanent reviewer outcome', () => {
        render(
            <DeviceManagementSection
                deviceId={42}
                profile={profile({
                    actions: [],
                    summary: {
                        declared: 0,
                        available: 0,
                        awaitingApproval: 0,
                        uncertain: 0,
                        blocked: 0,
                        breakGlassReviewDue: 1,
                    },
                    history: [
                        {
                            id: 91,
                            uuid: 'break-glass-review-due',
                            capability: 'access.door.unlock_timed',
                            label: 'Unlock door temporarily',
                            status: 'reconciled',
                            risk: 'high',
                            confirmationMode: 'acknowledge_impact',
                            impactAcknowledgedAt: '2026-07-24T01:00:00Z',
                            reason: 'Permit the verified response technician through the service entrance.',
                            safeParameters: { duration_seconds: 15 },
                            expectedState: { locked: true },
                            requestedBy: 'Emergency requester',
                            approvedBy: null,
                            isBreakGlass: true,
                            breakGlass: {
                                reviewer: 'Independent IT reviewer',
                                emergencyReason:
                                    'A person was trapped and required immediate access.',
                                declaredAt: '2026-07-24T01:00:00Z',
                                notificationSentAt: '2026-07-24T01:00:01Z',
                                reviewDueAt: '2026-07-25T01:00:00Z',
                                reviewedBy: null,
                                reviewedAt: null,
                                outcome: null,
                                reviewSummary: null,
                                overdue: false,
                            },
                            requestedAt: '2026-07-24T01:00:00Z',
                            expiresAt: '2026-07-24T01:02:00Z',
                            reconciledAt: '2026-07-24T01:01:00Z',
                            safeFailureReason: null,
                            blockedReasonCode: null,
                            blockedAt: null,
                            change: null,
                            nextAction: 'Post-use review is required.',
                            evidenceExportHref:
                                '/security-devices/devices/44/commands/91/evidence',
                            executionRoute: 'central',
                            latestAttempt: {
                                number: 1,
                                status: 'succeeded',
                                runtime: 'central',
                                safeResult: { provider_state: 'accepted' },
                                safeFailureReason: null,
                                completedAt: '2026-07-24T01:00:15Z',
                            },
                            latestReconciliation: {
                                outcome: 'matched',
                                observedState: { locked: true },
                                safeEvidenceSummary:
                                    'The door returned to locked.',
                                observedAt: '2026-07-24T01:01:00Z',
                            },
                            canDecide: false,
                            canDispatch: false,
                            dispatchPreconditionsCurrent: true,
                            canReviewBreakGlass: true,
                        },
                    ],
                })}
            />,
        );

        expect(screen.getByText('Break glass')).toBeInTheDocument();
        expect(screen.getByText(/Independent IT reviewer/)).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Complete post-use review',
            }),
        );
        expect(
            screen.getByText('Post-use break-glass review'),
        ).toBeInTheDocument();
        fireEvent.change(screen.getByLabelText('Review outcome'), {
            target: { value: 'follow_up_required' },
        });
        fireEvent.change(screen.getByLabelText('Permanent review summary'), {
            target: {
                value: 'The action was justified; add the failed egress door to the repair schedule.',
            },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Record permanent review' }),
        );

        expect(inertia.post).toHaveBeenCalledWith(
            '/security-devices/commands/91/break-glass-review',
            {
                outcome: 'follow_up_required',
                summary:
                    'The action was justified; add the failed egress door to the repair schedule.',
            },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('keeps approval and execution queue actions explicit in command history', () => {
        render(
            <DeviceManagementSection
                deviceId={42}
                profile={profile({
                    actions: [],
                    history: [
                        {
                            id: 11,
                            uuid: 'command-awaiting-approval',
                            capability: 'access.door.unlock_timed',
                            label: 'Unlock door temporarily',
                            status: 'awaiting_approval',
                            risk: 'high',
                            confirmationMode: 'acknowledge_impact',
                            impactAcknowledgedAt: '2026-07-24T01:00:00Z',
                            reason: 'Allow the verified technician through the service door.',
                            safeParameters: { duration_seconds: 20 },
                            expectedState: { locked: true },
                            requestedBy: 'Requester',
                            approvedBy: null,
                            isBreakGlass: false,
                            breakGlass: null,
                            requestedAt: '2026-07-24T01:00:00Z',
                            expiresAt: '2026-07-24T01:05:00Z',
                            reconciledAt: null,
                            safeFailureReason: null,
                            blockedReasonCode: null,
                            blockedAt: null,
                            change: null,
                            nextAction:
                                'An independent reviewer must verify the request.',
                            evidenceExportHref:
                                '/security-devices/devices/44/commands/11/evidence',
                            executionRoute: null,
                            latestAttempt: null,
                            latestReconciliation: null,
                            canDecide: true,
                            canDispatch: false,
                            dispatchPreconditionsCurrent: true,
                            canReviewBreakGlass: false,
                        },
                        {
                            id: 12,
                            uuid: 'command-ready',
                            capability: 'access.door.unlock_timed',
                            label: 'Unlock door temporarily',
                            status: 'ready',
                            risk: 'high',
                            confirmationMode: 'acknowledge_impact',
                            impactAcknowledgedAt: '2026-07-24T01:00:00Z',
                            reason: 'Allow the verified technician through the service door.',
                            safeParameters: { duration_seconds: 20 },
                            expectedState: { locked: true },
                            requestedBy: 'Requester',
                            approvedBy: 'Independent reviewer',
                            isBreakGlass: false,
                            breakGlass: null,
                            requestedAt: '2026-07-24T01:00:00Z',
                            expiresAt: '2026-07-24T01:05:00Z',
                            reconciledAt: null,
                            safeFailureReason: null,
                            blockedReasonCode: null,
                            blockedAt: null,
                            change: null,
                            nextAction:
                                'Add this request to the governed execution queue.',
                            evidenceExportHref:
                                '/security-devices/devices/44/commands/12/evidence',
                            executionRoute: null,
                            latestAttempt: null,
                            latestReconciliation: null,
                            canDecide: false,
                            canDispatch: true,
                            dispatchPreconditionsCurrent: true,
                            canReviewBreakGlass: false,
                        },
                    ],
                })}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Review' }));
        expect(
            screen.getByText(/independent reviewer must verify/i),
        ).toBeInTheDocument();
        const reviewDialog = screen.getByRole('dialog');
        expect(
            within(reviewDialog).getByText('Harbour service door'),
        ).toBeInTheDocument();
        expect(
            within(reviewDialog).getByText('Harbour Site'),
        ).toBeInTheDocument();
        expect(
            within(reviewDialog).getByText(
                'Allow the verified technician through the service door.',
            ),
        ).toBeInTheDocument();
        expect(
            within(reviewDialog).getByText('Locked: Yes'),
        ).toBeInTheDocument();
        fireEvent.change(screen.getByLabelText('Decision comment'), {
            target: {
                value: 'Identity, Site and service window were verified.',
            },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Record decision' }),
        );
        expect(inertia.post).toHaveBeenCalledWith(
            '/security-devices/commands/11/decision',
            {
                decision: 'approved',
                comment: 'Identity, Site and service window were verified.',
            },
            expect.objectContaining({ preserveScroll: true }),
        );

        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Add to execution queue' }),
        );
        expect(inertia.post).toHaveBeenCalledWith(
            '/security-devices/commands/12/dispatch',
            {},
            { preserveScroll: true },
        );
    });

    it('shows a terminal blocked request with the safe governance cause and no execution action', () => {
        render(
            <DeviceManagementSection
                deviceId={42}
                profile={profile({
                    actions: [],
                    summary: {
                        declared: 0,
                        available: 0,
                        awaitingApproval: 0,
                        uncertain: 0,
                        blocked: 1,
                        breakGlassReviewDue: 0,
                    },
                    history: [
                        {
                            id: 13,
                            uuid: 'command-blocked',
                            capability: 'access.door.unlock_timed',
                            label: 'Unlock door temporarily',
                            status: 'blocked',
                            risk: 'high',
                            confirmationMode: 'acknowledge_impact',
                            impactAcknowledgedAt: '2026-07-24T01:00:00Z',
                            reason: 'Allow the verified technician through the service door.',
                            safeParameters: { duration_seconds: 20 },
                            expectedState: { locked: true },
                            requestedBy: 'Requester',
                            approvedBy: 'Independent reviewer',
                            isBreakGlass: false,
                            breakGlass: null,
                            requestedAt: '2026-07-24T01:00:00Z',
                            expiresAt: '2026-07-24T01:05:00Z',
                            reconciledAt: null,
                            safeFailureReason:
                                'The last confirmed Device observation became stale. The request was blocked without execution.',
                            blockedReasonCode: 'observation_stale',
                            blockedAt: '2026-07-24T01:03:00Z',
                            change: null,
                            nextAction:
                                'Resolve the recorded governance condition and create a new request. This request cannot execute or be resumed.',
                            evidenceExportHref:
                                '/security-devices/devices/44/commands/13/evidence',
                            executionRoute: null,
                            latestAttempt: null,
                            latestReconciliation: null,
                            canDecide: false,
                            canDispatch: false,
                            dispatchPreconditionsCurrent: false,
                            canReviewBreakGlass: false,
                        },
                    ],
                })}
            />,
        );

        expect(screen.getByText('Blocked safely')).toBeInTheDocument();
        expect(screen.getByText(/observation stale/)).toBeInTheDocument();
        expect(
            screen.getByText(/blocked without execution/i),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/cannot execute or be resumed/i),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /execution queue/i }),
        ).not.toBeInTheDocument();
    });
});

describe('Native scheduled restart request', () => {
    it('submits strict UTC for the reviewed NZ time and preserves an unknown request', () => {
        const clock = vi
            .spyOn(Date, 'now')
            .mockReturnValue(Date.parse('2026-10-08T00:00:00Z'));
        try {
            const restartAction = {
                ...serviceAction,
                key: 'endpoint.computer.restart',
                label: 'Schedule computer restart',
                parameters: [
                    {
                        name: 'execute_at',
                        label: 'Restart time',
                        type: 'date_time' as const,
                        min: null,
                        max: null,
                        options: [],
                    },
                    {
                        name: 'warning_seconds',
                        label: 'Warning seconds',
                        type: 'integer' as const,
                        min: 60,
                        max: 3600,
                        options: [],
                    },
                ],
            };
            render(
                <DeviceManagementSection
                    deviceId={42}
                    profile={profile({
                        actorId: 9001,
                        actions: [restartAction],
                        stepUpCurrent: true,
                    })}
                />,
            );
            expect(
                screen.getByText(/Ends 5 seconds after the scheduled time/),
            ).toBeInTheDocument();
            fireEvent.click(
                screen.getByRole('button', { name: 'Request action' }),
            );
            fireEvent.change(
                screen.getByLabelText('Scheduled restart (New Zealand time)'),
                { target: { value: '2026-10-08T16:00' } },
            );
            fireEvent.change(screen.getByLabelText('Operational reason'), {
                target: {
                    value: 'Restart after staff save their current work.',
                },
            });
            fireEvent.click(
                screen.getByRole('checkbox', {
                    name: /I understand this impact/,
                }),
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Create request' }),
            );
            const first = inertia.post.mock.calls[0];
            expect(first[1].parameters).toEqual({
                execute_at: '2026-10-08T03:00:00Z',
                warning_seconds: 60,
            });
            act(() => first[2].onFinish());
            clock.mockReturnValue(Date.parse('2026-10-09T00:00:00Z'));
            fireEvent.click(
                screen.getByRole('button', { name: 'Retry same request' }),
            );
            expect(inertia.post.mock.calls[1][1]).toEqual(first[1]);
            expect(first[1].expected_actor_id).toBe(9001);
        } finally {
            clock.mockRestore();
        }
    });
});
