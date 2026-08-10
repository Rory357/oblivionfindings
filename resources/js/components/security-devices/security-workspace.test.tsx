import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import {
    AccessControlWorkspace,
    type AccessControlWorkspaceData,
} from './access-control-workspace';
import {
    SecurityWorkspacePanels,
    type SecurityWorkspaceData,
} from './security-workspace';

const base: SecurityWorkspaceData = {
    permissions: {
        events: true,
        maintenance: true,
        control_room: true,
        cctv_media: true,
    },
    overview: {
        inventory: {
            total: 8,
            cctv: 3,
            alarms: 2,
            access_control: 2,
            other: 1,
        },
        attention: {
            devices: 2,
            sites: 1,
            overdue_maintenance: 1,
            unprocessed_events: 2,
            active_control_room_alerts: 1,
        },
        requiredActions: [
            {
                key: 'offline_devices',
                label: 'Offline security devices',
                count: 2,
                description: 'Restore or investigate offline devices.',
                href: '/security-devices/security?status=offline',
            },
            {
                key: 'active_control_room_alerts',
                label: 'Active Control Room alerts',
                count: 1,
                description: 'Open canonical operational triage.',
                href: '/control-room/alerts?source=security_devices',
            },
        ],
    },
    activeTab: {
        key: 'overview',
        label: 'Overview',
        description: 'Physical security posture.',
        restricted: false,
        inventoryTotal: 8,
        inventoryShown: 2,
        inventoryTruncated: false,
        devices: [],
        recentEvents: [],
        controlRoomAlerts: [],
    },
};

const managedAccessControl: AccessControlWorkspaceData = {
    restricted: false,
    canManage: true,
    summary: {
        activeCredentials: 2,
        activeSchedules: 1,
        coveredDoors: 1,
    },
    sites: [{ id: 9, name: 'Harbour House' }],
    deviceOptions: [],
    holderOptions: [],
    providerActions: {
        issue: {
            available: false,
            reason: 'No approved credential-issue adapter is connected.',
        },
        revoke: {
            available: false,
            reason: 'No approved credential-revocation adapter is connected.',
        },
    },
    schedules: [
        {
            id: 2,
            siteId: 9,
            siteName: 'Harbour House',
            name: 'Weekday staff access',
            days: ['monday', 'tuesday'],
            startsAt: '08:00',
            endsAt: '18:00',
            timezone: 'Pacific/Auckland',
            isActive: true,
            version: 3,
            activeCredentials: 2,
            impact: {
                activeCredentials: 2,
                requiresExactConfirmation: true,
                updateConfirmation: 'UPDATE 2',
                deactivateConfirmation: 'DEACTIVATE 2',
            },
            providerReconciliation: {
                status: 'required',
                label: 'Provider reconciliation required',
                tone: 'warning',
                requiredAt: '2026-08-06T01:00:00.000Z',
                message:
                    'Saved in Oblivion Findings only. Provider-side schedule execution has not been claimed and must be reconciled separately.',
                failureReason: null,
                providerConfirmed: false,
            },
            deactivatedAt: null,
            deactivationReason: null,
            revisionHistory: [
                {
                    id: 21,
                    version: 3,
                    action: 'updated',
                    reason: 'Approved operating-hours change',
                    activeCredentialsAffected: 2,
                    actor: 'Alex Admin',
                    occurredAt: '2026-08-06T01:00:00.000Z',
                },
            ],
        },
    ],
    credentials: [],
    history: [],
};

describe('SecurityWorkspacePanels', () => {
    it('previews exact active-credential impact and keeps provider execution truthful for schedule changes', () => {
        render(<AccessControlWorkspace data={managedAccessControl} />);

        expect(
            screen.getByText('Provider reconciliation required'),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Edit schedule' }));
        expect(screen.getByText('Current impact preview')).toBeInTheDocument();
        expect(
            screen.getByText(
                '2 active credentials currently use this schedule.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByLabelText('Type UPDATE 2 exactly'),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Deactivate' }));
        expect(
            screen.getByLabelText('Type DEACTIVATE 2 exactly'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                /Existing credentials are not falsely presented as revoked/,
            ),
        ).toBeInTheDocument();
    });

    it('renders the actual schedule provider reconciliation state', () => {
        const schedule = managedAccessControl.schedules[0];
        const { rerender } = render(
            <AccessControlWorkspace
                data={{
                    ...managedAccessControl,
                    schedules: [
                        {
                            ...schedule,
                            providerReconciliation: {
                                status: 'reconciled',
                                label: 'Provider reconciled',
                                tone: 'positive',
                                requiredAt: '2026-08-06T01:00:00.000Z',
                                message:
                                    'Provider evidence confirms this schedule projection is reconciled.',
                                failureReason: null,
                                providerConfirmed: true,
                            },
                        },
                    ],
                }}
            />,
        );

        expect(screen.getByText('Provider reconciled')).toBeInTheDocument();
        expect(
            screen.queryByText('Provider reconciliation required'),
        ).not.toBeInTheDocument();

        rerender(
            <AccessControlWorkspace
                data={{
                    ...managedAccessControl,
                    schedules: [
                        {
                            ...schedule,
                            providerReconciliation: {
                                status: 'failed',
                                label: 'Provider reconciliation failed',
                                tone: 'danger',
                                requiredAt: '2026-08-06T01:00:00.000Z',
                                message:
                                    'Provider reconciliation failed. The saved schedule must not be treated as enforced.',
                                failureReason:
                                    'Provider rejected the schedule.',
                                providerConfirmed: false,
                            },
                        },
                    ],
                }}
            />,
        );

        expect(
            screen.getByText('Provider reconciliation failed'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Provider rejected the schedule.'),
        ).toBeInTheDocument();
    });

    it('labels credential provider evidence without treating local rows as granted or revoked access', () => {
        const credential = {
            id: 4,
            siteId: 9,
            siteName: 'Harbour House',
            label: 'Reception badge',
            holderType: 'staff',
            holderLabel: 'Taylor Worker',
            referenceKey: 'unifi:credential/taylor-001',
            status: 'pending_issue',
            scheduleName: 'Weekday staff access',
            devices: [],
            validFrom: null,
            validUntil: null,
            revokedAt: null,
            revocationReason: null,
        };

        render(
            <AccessControlWorkspace
                data={{
                    ...managedAccessControl,
                    credentials: [
                        {
                            ...credential,
                            providerLifecycle: {
                                state: 'pending',
                                label: 'Access not confirmed',
                                tone: 'warning',
                                message:
                                    'A local credential record exists, but the provider has not confirmed access was granted.',
                                requestedAt: '2026-08-06T01:00:00.000Z',
                                confirmedAt: null,
                                failureReason: null,
                                accessStillConfirmed: false,
                            },
                        },
                        {
                            ...credential,
                            id: 5,
                            label: 'Former staff badge',
                            status: 'pending_revoke',
                            providerLifecycle: {
                                state: 'pending',
                                label: 'Revocation not confirmed',
                                tone: 'warning',
                                message:
                                    'A local revocation record exists, but the provider has not confirmed access was removed.',
                                requestedAt: '2026-08-06T02:00:00.000Z',
                                confirmedAt: null,
                                failureReason: null,
                                accessStillConfirmed: false,
                            },
                        },
                        {
                            ...credential,
                            id: 6,
                            label: 'Failed badge request',
                            status: 'issue_failed',
                            providerLifecycle: {
                                state: 'failed',
                                label: 'Issue failed',
                                tone: 'danger',
                                message:
                                    'The provider action did not reconcile. Do not assume physical access changed.',
                                requestedAt: '2026-08-06T03:00:00.000Z',
                                confirmedAt: null,
                                failureReason: 'Provider rejected the request.',
                                accessStillConfirmed: false,
                            },
                        },
                    ],
                }}
            />,
        );

        expect(screen.getByText('Access not confirmed')).toBeInTheDocument();
        expect(
            screen.getAllByText(
                'No current provider-confirmed Site-bound reader is attached.',
            ),
        ).toHaveLength(3);
        expect(
            screen.getByText('Revocation not confirmed'),
        ).toBeInTheDocument();
        expect(screen.getByText('Issue failed')).toBeInTheDocument();
        expect(
            screen.getByText('Provider rejected the request.'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Provider issue action unavailable'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /issue|revoke|revocation/i }),
        ).not.toBeInTheDocument();
    });

    it('makes the overview and required actions immediately understandable', () => {
        render(<SecurityWorkspacePanels data={base} />);

        expect(
            screen.getByRole('heading', { name: 'Security at a glance' }),
        ).toBeInTheDocument();
        expect(screen.getByText('3 CCTV')).toBeInTheDocument();
        expect(screen.getByText('2 alarms')).toBeInTheDocument();
        expect(screen.getByText('2 access control')).toBeInTheDocument();
        expect(
            screen.getByText('2 devices need attention'),
        ).toBeInTheDocument();
        expect(screen.getByText('1 site affected')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /Offline security devices/ }),
        ).toHaveAttribute('href', '/security-devices/security?status=offline');
        expect(
            screen.getByRole('link', { name: /Active Control Room alerts/ }),
        ).toHaveAttribute(
            'href',
            '/control-room/alerts?source=security_devices',
        );
    });

    it('shows observed CCTV evidence, assignment, maintenance and authorised media without command controls', () => {
        render(
            <SecurityWorkspacePanels
                data={{
                    ...base,
                    activeTab: {
                        ...base.activeTab,
                        key: 'cctv',
                        label: 'CCTV',
                        inventoryTotal: 1,
                        inventoryShown: 1,
                        devices: [
                            {
                                id: 11,
                                name: 'Reception camera',
                                category: 'cctv',
                                subcategory: 'dome_camera',
                                provider: 'unifi',
                                status: 'active',
                                health: 'warning',
                                lastSeenAt: '2026-07-19T03:00:00.000Z',
                                deviceHref: '/security-devices/devices/11',
                                site: {
                                    id: 9,
                                    name: 'Harbour House',
                                    href: '/security-devices/sites/9',
                                },
                                assignment: {
                                    type: 'site',
                                    label: 'Harbour House',
                                    href: null,
                                },
                                monitoring: { state: 'configured', count: 2 },
                                observed: {
                                    stream_health: 'healthy',
                                    recording_health: 'degraded',
                                },
                                maintenance: {
                                    open_count: 1,
                                    overdue_count: 0,
                                    next: {
                                        id: 5,
                                        type: 'inspection',
                                        status: 'scheduled',
                                        description: 'Lens inspection',
                                        scheduledFor:
                                            '2026-07-21T00:00:00.000Z',
                                    },
                                },
                                media: {
                                    state: 'available',
                                    href: '/security-devices/devices/11/media',
                                },
                            },
                        ],
                    },
                }}
            />,
        );

        const device = screen.getByRole('article', {
            name: 'Reception camera',
        });
        expect(within(device).getByText('Harbour House')).toBeInTheDocument();
        expect(within(device).getByText('Stream: Healthy')).toBeInTheDocument();
        expect(
            within(device).getByText('Recording: Degraded'),
        ).toBeInTheDocument();
        expect(within(device).getByText('Lens inspection')).toBeInTheDocument();
        expect(
            within(device).getByRole('link', { name: 'Open authorised media' }),
        ).toHaveAttribute('href', '/security-devices/devices/11/media');
        expect(
            within(device).queryByRole('button', {
                name: /unlock|arm|restart/i,
            }),
        ).not.toBeInTheDocument();
    });

    it('is honest when an integration has not reported specialist state or media access is restricted', () => {
        render(
            <SecurityWorkspacePanels
                data={{
                    ...base,
                    permissions: { ...base.permissions, cctv_media: false },
                    activeTab: {
                        ...base.activeTab,
                        key: 'cctv',
                        label: 'CCTV',
                        inventoryTotal: 1,
                        inventoryShown: 1,
                        devices: [
                            {
                                id: 12,
                                name: 'Unobserved camera',
                                category: 'cctv',
                                subcategory: 'bullet_camera',
                                provider: 'manual',
                                status: 'active',
                                health: 'unknown',
                                lastSeenAt: null,
                                deviceHref: '/security-devices/devices/12',
                                site: null,
                                assignment: null,
                                monitoring: {
                                    state: 'unmonitored',
                                    count: 0,
                                },
                                observed: {},
                                maintenance: null,
                                media: { state: 'restricted' },
                            },
                        ],
                    },
                }}
            />,
        );

        expect(
            screen.getByText('Specialist state not reported by integration'),
        ).toBeInTheDocument();
        expect(screen.getByText('Media access restricted')).toBeInTheDocument();
        expect(screen.queryByText(/stream healthy/i)).not.toBeInTheDocument();
    });

    it('links a person assignment only when the permission-filtered presenter supplies a canonical href', () => {
        const assignedDevice = {
            id: 13,
            name: 'Aroha safety panel',
            category: 'alarm',
            subcategory: 'personal_alarm',
            provider: null,
            status: 'active',
            health: 'healthy',
            lastSeenAt: null,
            deviceHref: '/security-devices/devices/13',
            site: null,
            assignment: {
                type: 'client',
                label: 'Aroha',
                href: '/operations/clients/21',
            },
            monitoring: { state: 'configured', count: 1 },
            observed: { alarm_state: 'ready' },
            maintenance: null,
        };
        const { rerender } = render(
            <SecurityWorkspacePanels
                data={{
                    ...base,
                    activeTab: {
                        ...base.activeTab,
                        key: 'alarms',
                        label: 'Alarms',
                        inventoryTotal: 1,
                        inventoryShown: 1,
                        devices: [assignedDevice],
                    },
                }}
            />,
        );

        const clientDevice = screen.getByRole('article', {
            name: assignedDevice.name,
        });
        expect(
            within(clientDevice).getByText('Assigned person'),
        ).toBeInTheDocument();
        expect(
            within(clientDevice).getByRole('link', { name: 'Aroha' }),
        ).toHaveAttribute('href', '/operations/clients/21');

        rerender(
            <SecurityWorkspacePanels
                data={{
                    ...base,
                    activeTab: {
                        ...base.activeTab,
                        key: 'alarms',
                        label: 'Alarms',
                        inventoryTotal: 1,
                        inventoryShown: 1,
                        devices: [
                            {
                                ...assignedDevice,
                                name: 'Worker safety panel',
                                assignment: {
                                    type: 'staff',
                                    label: 'Taylor Worker',
                                    href: null,
                                },
                            },
                        ],
                    },
                }}
            />,
        );

        const staffDevice = screen.getByRole('article', {
            name: 'Worker safety panel',
        });
        expect(
            within(staffDevice).getByText('Taylor Worker'),
        ).toBeInTheDocument();
        expect(
            within(staffDevice).queryByRole('link', {
                name: 'Taylor Worker',
            }),
        ).not.toBeInTheDocument();
    });

    it('does not describe restricted security-event history as empty', () => {
        render(
            <SecurityWorkspacePanels
                data={{
                    ...base,
                    permissions: { ...base.permissions, events: false },
                    activeTab: {
                        ...base.activeTab,
                        key: 'alarms',
                        label: 'Alarms',
                    },
                }}
            />,
        );

        expect(
            screen.getByText(
                'Security-event history is restricted by permission.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(
                'No canonical security-device events are recorded.',
            ),
        ).not.toBeInTheDocument();
    });

    it('shows canonical event history and links Control Room context without duplicating alert actions', () => {
        render(
            <SecurityWorkspacePanels
                data={{
                    ...base,
                    activeTab: {
                        ...base.activeTab,
                        key: 'events',
                        label: 'Security events',
                        inventoryTotal: 1,
                        inventoryShown: 1,
                        recentEvents: [
                            {
                                id: 44,
                                type: 'door_opened',
                                severity: 'info',
                                source: 'unifi',
                                occurredAt: '2026-07-19T03:00:00.000Z',
                                processedAt: '2026-07-19T03:00:01.000Z',
                                device: {
                                    id: 11,
                                    name: 'Staff entrance reader',
                                    href: '/security-devices/devices/11',
                                },
                                context: { direction: 'entry' },
                            },
                        ],
                        controlRoomAlerts: [
                            {
                                id: 71,
                                reference: 'CR-0071',
                                title: 'Door forced open',
                                severity: 'critical',
                                status: 'open',
                                triggeredAt: '2026-07-19T03:00:00.000Z',
                                canonicalDeviceId: 11,
                                href: '/control-room/alerts/71',
                                access: {
                                    state: 'available',
                                    label: 'Open Control Room alert',
                                },
                            },
                        ],
                    },
                }}
            />,
        );

        expect(screen.getByText('Door opened')).toBeInTheDocument();
        expect(screen.getByText('Staff entrance reader')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /Door forced open/ }),
        ).toHaveAttribute('href', '/control-room/alerts/71');
        expect(
            screen.queryByRole('button', { name: /acknowledge|resolve/i }),
        ).not.toBeInTheDocument();
    });

    it('retains restricted Control Room context without an enabled alert link', () => {
        render(
            <SecurityWorkspacePanels
                data={{
                    ...base,
                    activeTab: {
                        ...base.activeTab,
                        key: 'events',
                        label: 'Security events',
                        controlRoomAlerts: [
                            {
                                id: 72,
                                reference: null,
                                title: 'Control Room alert',
                                severity: null,
                                status: null,
                                triggeredAt: null,
                                canonicalDeviceId: null,
                                href: null,
                                access: {
                                    state: 'restricted',
                                    label: 'Control Room alert access required',
                                },
                            },
                        ],
                    },
                }}
            />,
        );

        expect(
            screen.getByText('Control Room alert access required'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: /control room alert/i }),
        ).not.toBeInTheDocument();
    });

    it('shows Site-bound access schedules, safe credential references and audit history to an authorised read-only role', () => {
        render(
            <SecurityWorkspacePanels
                data={{
                    ...base,
                    activeTab: {
                        ...base.activeTab,
                        key: 'access-control',
                        label: 'Access Control',
                        accessControl: {
                            restricted: false,
                            canManage: false,
                            summary: {
                                activeCredentials: 1,
                                activeSchedules: 1,
                                coveredDoors: 1,
                            },
                            sites: [{ id: 9, name: 'Harbour House' }],
                            deviceOptions: [],
                            holderOptions: [],
                            providerActions: {
                                issue: {
                                    available: false,
                                    reason: 'No approved credential-issue adapter is connected.',
                                },
                                revoke: {
                                    available: false,
                                    reason: 'No approved credential-revocation adapter is connected.',
                                },
                            },
                            schedules: [
                                {
                                    id: 2,
                                    siteId: 9,
                                    siteName: 'Harbour House',
                                    name: 'Weekday staff access',
                                    days: ['monday', 'tuesday'],
                                    startsAt: '08:00',
                                    endsAt: '18:00',
                                    timezone: 'Pacific/Auckland',
                                    isActive: true,
                                    version: 2,
                                    activeCredentials: 1,
                                    impact: {
                                        activeCredentials: 1,
                                        requiresExactConfirmation: true,
                                        updateConfirmation: 'UPDATE 1',
                                        deactivateConfirmation: 'DEACTIVATE 1',
                                    },
                                    providerReconciliation: {
                                        status: 'required',
                                        label: 'Provider reconciliation required',
                                        tone: 'warning',
                                        requiredAt: '2026-08-05T09:00:00.000Z',
                                        message:
                                            'Saved in Oblivion Findings only. Provider-side schedule execution has not been claimed and must be reconciled separately.',
                                        failureReason: null,
                                        providerConfirmed: false,
                                    },
                                    deactivatedAt: null,
                                    deactivationReason: null,
                                    revisionHistory: [],
                                },
                            ],
                            credentials: [
                                {
                                    id: 4,
                                    siteId: 9,
                                    siteName: 'Harbour House',
                                    label: 'Reception badge',
                                    holderType: 'staff',
                                    holderLabel: 'Taylor Worker',
                                    referenceKey: 'unifi:credential/taylor-001',
                                    status: 'active',
                                    providerLifecycle: {
                                        state: 'active',
                                        label: 'Active — provider confirmed',
                                        tone: 'positive',
                                        message:
                                            'The provider reconciliation evidence confirms this credential is active.',
                                        requestedAt: '2026-08-05T09:59:00.000Z',
                                        confirmedAt: '2026-08-05T10:00:00.000Z',
                                        failureReason: null,
                                        accessStillConfirmed: true,
                                    },
                                    scheduleName: 'Weekday staff access',
                                    devices: [
                                        {
                                            id: 11,
                                            name: 'Staff entrance reader',
                                            href: '/security-devices/devices/11',
                                        },
                                    ],
                                    validFrom: null,
                                    validUntil: null,
                                    revokedAt: null,
                                    revocationReason: null,
                                },
                            ],
                            history: [
                                {
                                    id: 'audit:8',
                                    action: 'Credential registration recorded — provider not confirmed',
                                    actor: 'Alex Admin',
                                    occurredAt: '2026-08-05T10:00:00.000Z',
                                },
                            ],
                        },
                    },
                }}
            />,
        );

        expect(
            screen.getByText('Provider-confirmed active credentials'),
        ).toBeInTheDocument();
        expect(screen.getAllByText('Weekday staff access')).toHaveLength(2);
        expect(screen.getByText(/Taylor Worker/)).toBeInTheDocument();
        expect(
            screen.getByText('unifi:credential/taylor-001'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Active — provider confirmed'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Credential registration recorded — provider not confirmed',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Provider reconciliation required'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                /Provider-side schedule execution has not been claimed/,
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /revoke|revocation/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /issue/i }),
        ).not.toBeInTheDocument();
    });

    it('keeps access credential and history data hidden from a general device viewer', () => {
        render(
            <SecurityWorkspacePanels
                data={{
                    ...base,
                    activeTab: {
                        ...base.activeTab,
                        key: 'access-control',
                        label: 'Access Control',
                        accessControl: {
                            restricted: true,
                            canManage: false,
                            summary: {
                                activeCredentials: 0,
                                activeSchedules: 0,
                                coveredDoors: 0,
                            },
                            sites: [],
                            deviceOptions: [],
                            holderOptions: [],
                            providerActions: {
                                issue: {
                                    available: false,
                                    reason: 'No approved credential-issue adapter is connected.',
                                },
                                revoke: {
                                    available: false,
                                    reason: 'No approved credential-revocation adapter is connected.',
                                },
                            },
                            schedules: [],
                            credentials: [],
                            history: [],
                        },
                    },
                }}
            />,
        );

        expect(
            screen.getByText('Physical access records are restricted'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Credential lifecycle'),
        ).not.toBeInTheDocument();
    });
});
