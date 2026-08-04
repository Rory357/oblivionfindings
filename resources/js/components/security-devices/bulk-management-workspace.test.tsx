import { fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
    BulkManagementWorkspace,
    type BulkManagementWorkspaceData,
} from './bulk-management-workspace';

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
    risk: 'high' as const,
    level: 'control',
    sensitivity: 'security_control',
    impact: 'Temporarily unlocks every included service door.',
    expectedResult:
        'Each provider confirms that its exact door returns to locked.',
    requiresStepUp: true,
    requiresMfa: false,
    requiresFreshObservation: true,
    requiresApproval: true,
    requiresChange: false,
    expiresAfterSeconds: 300,
    confirmationMode: 'acknowledge_impact',
    eligibleCount: 2,
    declaredCount: 2,
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

function workspace(
    overrides: Partial<BulkManagementWorkspaceData> = {},
): BulkManagementWorkspaceData {
    return {
        workspace: 'security',
        actions: [action],
        devices: [
            {
                id: 11,
                name: 'North service door',
                uid: 'DOOR-11',
                category: 'access_control',
                subcategory: 'door_controller',
                provider: 'unifi',
                status: 'active',
                health: 'healthy',
                siteId: 4,
                siteName: 'Kauri House',
                changeOptions: [],
                actions: {
                    [action.key]: {
                        available: true,
                        state: 'available',
                        reason: 'Ready for governed request validation.',
                    },
                },
            },
            {
                id: 12,
                name: 'South service door',
                uid: 'DOOR-12',
                category: 'access_control',
                subcategory: 'door_controller',
                provider: 'unifi',
                status: 'active',
                health: 'healthy',
                siteId: 4,
                siteName: 'Kauri House',
                changeOptions: [],
                actions: {
                    [action.key]: {
                        available: true,
                        state: 'available',
                        reason: 'Ready for governed request validation.',
                    },
                },
            },
        ],
        candidateCount: 2,
        totalVisibleCount: 2,
        truncated: false,
        targetLimit: 100,
        canObserve: true,
        canRequest: true,
        stepUpCurrent: false,
        recentBatches: [],
        ...overrides,
    };
}

describe('BulkManagementWorkspace', () => {
    beforeEach(() => {
        inertia.post.mockReset();
        window.history.replaceState(
            {},
            '',
            '/security-devices/tracking/management',
        );
    });

    it('makes target, Site, impact, independent lifecycle and step-up requirements clear before submission', () => {
        render(<BulkManagementWorkspace data={workspace()} />);

        expect(
            screen.getByRole('heading', {
                name: 'Governed Device management',
            }),
        ).toBeInTheDocument();
        expect(screen.getByText('Independent approval')).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Select all ready' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Review selected targets' }),
        );

        const dialog = screen.getByRole('dialog');
        expect(
            within(dialog).getByRole('heading', {
                name: 'Review governed bulk action',
            }),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText('Included targets'),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText('Explicit exclusions'),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText(/independent child requests/i),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText('Partial-result rule'),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByRole('link', { name: /Confirm identity/ }),
        ).toHaveAttribute(
            'href',
            '/security-devices/command-batches/confirm-identity?workspace=security',
        );
        expect(
            within(dialog).getByRole('button', {
                name: 'Create 2 child requests',
            }),
        ).toBeDisabled();
    });

    it('posts the exact reviewed target list and shared safe parameters after elevated confirmation', () => {
        render(
            <BulkManagementWorkspace
                data={workspace({ stepUpCurrent: true })}
            />,
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'Select all ready' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Review selected targets' }),
        );
        const dialog = screen.getByRole('dialog');
        fireEvent.change(within(dialog).getByLabelText('Duration Seconds'), {
            target: { value: '20' },
        });
        fireEvent.change(within(dialog).getByLabelText('Operational reason'), {
            target: {
                value: 'Allow the verified engineering team through both service doors.',
            },
        });
        fireEvent.click(
            within(dialog).getByLabelText(/I understand the combined impact/i),
        );
        fireEvent.change(within(dialog).getByLabelText(/BULK 2 DEVICES/), {
            target: { value: 'BULK 2 DEVICES' },
        });
        fireEvent.click(
            within(dialog).getByRole('button', {
                name: 'Create 2 child requests',
            }),
        );

        expect(inertia.post).toHaveBeenCalledWith(
            '/security-devices/command-batches',
            expect.objectContaining({
                workspace: 'security',
                device_ids: [11, 12],
                capability: 'access.door.unlock_timed',
                parameters: { duration_seconds: 20 },
                reason: 'Allow the verified engineering team through both service doors.',
                idempotency_key: expect.stringMatching(/^bulk_security_/),
                impact_acknowledged: true,
                confirmation_text: 'BULK 2 DEVICES',
            }),
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('accepts a provider handoff only for declared devices, action, and compatible profile options', () => {
        const configurationApply = {
            ...action,
            key: 'configuration.apply',
            label: 'Apply configuration',
            requiresChange: false,
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
        const data = workspace({
            actions: [configurationApply],
        });
        data.devices = data.devices.map((device) => ({
            ...device,
            actions: {
                [configurationApply.key]: {
                    available: true,
                    state: 'available',
                    reason: 'Ready for governed request validation.',
                },
            },
        }));
        window.history.replaceState(
            {},
            '',
            '/security-devices/tracking/management?bulk_action=configuration.apply&bulk_device_ids=11,12&bulk_configuration_profile_id=73',
        );

        render(<BulkManagementWorkspace data={data} />);

        expect(screen.getByLabelText('Management action')).toHaveValue(
            'configuration.apply',
        );
        expect(
            screen.getByLabelText('Select North service door'),
        ).toBeChecked();
        expect(
            screen.getByLabelText('Select South service door'),
        ).toBeChecked();
        fireEvent.click(
            screen.getByRole('button', { name: 'Review selected targets' }),
        );
        const dialog = screen.getByRole('dialog');
        expect(
            within(dialog).getByLabelText('Configuration Profile'),
        ).toHaveValue('73');
        expect(
            within(dialog).getByText('Resident safety · v2'),
        ).toBeInTheDocument();
    });

    it('keeps selected unavailable targets visible as exclusions', () => {
        const data = workspace();
        data.actions[0] = {
            ...action,
            eligibleCount: 1,
        };
        data.devices[1] = {
            ...data.devices[1],
            actions: {
                [action.key]: {
                    available: false,
                    state: 'provider_adapter_required',
                    reason: 'No approved provider adapter is available.',
                },
            },
        };
        render(<BulkManagementWorkspace data={data} />);

        fireEvent.click(screen.getByLabelText('Select North service door'));
        fireEvent.click(screen.getByLabelText('Select South service door'));
        fireEvent.click(
            screen.getByRole('button', { name: 'Review selected targets' }),
        );

        const dialog = screen.getByRole('dialog');
        expect(
            within(dialog).getByText(
                'No approved provider adapter is available.',
            ),
        ).toBeInTheDocument();
    });

    it('links recent governed activity back to approval, progress and result evidence', () => {
        render(
            <BulkManagementWorkspace
                data={workspace({
                    recentBatches: [
                        {
                            id: 90,
                            uuid: 'batch-90',
                            label: 'Unlock door temporarily',
                            risk: 'high',
                            status: 'partially_completed',
                            requestedBy: 'Operations Manager',
                            requestedAt: '2026-07-27T03:00:00Z',
                            summary: {
                                selected: 3,
                                included: 2,
                                excluded: 1,
                                sites: 2,
                                awaitingApproval: 0,
                                ready: 0,
                                queuedOrRunning: 0,
                                terminal: 2,
                                reconciled: 1,
                                failedOrBlocked: 1,
                            },
                            href: '/security-devices/command-batches/90',
                        },
                    ],
                })}
            />,
        );

        expect(
            screen.getByRole('heading', { name: 'Recent governed activity' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Unlock door temporarily' }),
        ).toHaveAttribute('href', '/security-devices/command-batches/90');
        expect(
            screen.getByText('2 included · 1 excluded · 2 Sites'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('1 reconciled · 1 failed or blocked'),
        ).toBeInTheDocument();
    });
});
