import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
    MonitoringPolicySettings,
    type MonitoringPolicyWorkspace,
} from './monitoring-policy-settings';

vi.mock('axios');
vi.mock('@inertiajs/react', () => ({
    router: { reload: vi.fn() },
}));

const workspace: MonitoringPolicyWorkspace = {
    visible: true,
    can_manage: true,
    can_manage_application: true,
    retention_confirmation: 'CONFIRM RETENTION CHANGE',
    sites: [{ id: 14, name: 'Coast Site' }],
    devices: [
        {
            id: 41,
            name: 'SD-WAN gateway',
            site_id: 14,
            site_name: 'Coast Site',
        },
    ],
    monitors: [
        {
            id: 91,
            name: 'Gateway reachability',
            kind: 'icmp',
            device_id: 41,
            device_name: 'SD-WAN gateway',
            site_id: 14,
            site_name: 'Coast Site',
            enabled: true,
        },
    ],
    catalogs: {
        domains: [
            {
                value: 'it_infrastructure',
                label: 'IT Infrastructure',
                categories: [{ value: 'network', label: 'Network' }],
            },
        ],
        capabilities: [
            {
                value: 'reachability',
                label: 'Reachability',
                monitor_kind: 'icmp',
            },
        ],
        data_classes: ['operational'],
        privacy_classes: ['standard'],
        timezones: ['Pacific/Auckland'],
    },
    profiles: [],
    coverage: [],
    dependencies: [],
    maintenance: [],
    retention: [],
};

beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(axios.post).mockResolvedValue({ data: {} });
    vi.mocked(axios.patch).mockResolvedValue({ data: {} });
});

describe('monitoring policy settings', () => {
    it('presents five clear policy tabs without raw target or secret controls', () => {
        render(<MonitoringPolicySettings workspace={workspace} />);

        for (const name of [
            'Profiles',
            'Coverage',
            'Dependencies',
            'Maintenance',
            'Retention',
        ]) {
            expect(screen.getByRole('tab', { name })).toBeInTheDocument();
        }
        expect(screen.queryByText(/raw target/i)).not.toBeInTheDocument();
        expect(
            screen.queryByText(/password|secret value/i),
        ).not.toBeInTheDocument();
    });

    it('creates an application profile through the governed endpoint', async () => {
        render(<MonitoringPolicySettings workspace={workspace} />);
        fireEvent.click(screen.getByRole('button', { name: 'New profile' }));
        const name = screen.getAllByRole('textbox')[0];
        fireEvent.change(name, { target: { value: 'Core reachability' } });
        fireEvent.click(
            screen.getByRole('button', { name: 'Save governed change' }),
        );

        await waitFor(() => {
            expect(axios.post).toHaveBeenCalledWith(
                '/security-devices/settings/monitoring/profiles',
                expect.objectContaining({
                    name: 'Core reachability',
                    interval_seconds: 60,
                    failure_confirmations: 3,
                }),
            );
        });
        expect(axios.post).toHaveBeenCalledWith(
            expect.any(String),
            expect.not.objectContaining({ version: expect.anything() }),
        );
    });

    it('previews retention impact and requires exact confirmation before saving', async () => {
        vi.mocked(axios.post)
            .mockResolvedValueOnce({
                data: {
                    preview: {
                        metric_series_candidates: 12,
                        snapshot_candidates: 3,
                        requires_confirmation: true,
                        legal_hold_removal: false,
                        scope_changed: false,
                    },
                },
            })
            .mockResolvedValueOnce({ data: {} });
        render(<MonitoringPolicySettings workspace={workspace} />);
        fireEvent.mouseDown(screen.getByRole('tab', { name: 'Retention' }), {
            button: 0,
            ctrlKey: false,
        });
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'New retention policy',
            }),
        );
        fireEvent.change(screen.getAllByRole('textbox')[0], {
            target: { value: 'Application evidence' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Preview impact' }));

        await screen.findByText('Retention impact preview');
        expect(
            screen.getByText(/12 metric series and 3 configuration snapshots/),
        ).toBeInTheDocument();
        const save = screen.getByRole('button', {
            name: 'Save governed change',
        });
        expect(save).toBeDisabled();

        const confirmationLabel = screen.getByText(
            /Type “CONFIRM RETENTION CHANGE”/,
        );
        const confirmationInput =
            confirmationLabel.parentElement?.querySelector('input');
        const reasonLabel = screen.getByText('Reason for the retention change');
        const reasonInput =
            reasonLabel.parentElement?.querySelector('textarea');
        expect(confirmationInput).not.toBeNull();
        expect(reasonInput).not.toBeNull();
        fireEvent.change(confirmationInput!, {
            target: { value: 'CONFIRM RETENTION CHANGE' },
        });
        fireEvent.change(reasonInput!, {
            target: {
                value: 'Approved application evidence retention change.',
            },
        });
        fireEvent.click(save);

        await waitFor(() => {
            expect(axios.post).toHaveBeenLastCalledWith(
                '/security-devices/settings/monitoring/retention',
                expect.objectContaining({
                    confirmation: 'CONFIRM RETENTION CHANGE',
                    reason: 'Approved application evidence retention change.',
                }),
            );
        });
    });

    it('reactivates the existing governed coverage identity instead of creating a duplicate', async () => {
        render(
            <MonitoringPolicySettings
                workspace={{
                    ...workspace,
                    coverage: [
                        {
                            id: 71,
                            version: 2,
                            state: 'inactive',
                            is_active: false,
                            can_manage: true,
                            site_id: 14,
                            site_name: 'Coast Site',
                            device_domain: 'it_infrastructure',
                            device_category: 'network',
                            capability: 'reachability',
                            monitor_kind: 'icmp',
                            minimum_count: 1,
                            support_status: 'supported',
                            rationale:
                                'Every Site router requires reachability monitoring.',
                        },
                    ],
                }}
            />,
        );
        fireEvent.mouseDown(screen.getByRole('tab', { name: 'Coverage' }), {
            button: 0,
            ctrlKey: false,
        });
        fireEvent.click(
            await screen.findByRole('button', { name: 'Reactivate' }),
        );
        const reasonLabel = screen.getByText('Operational reason');
        const reasonInput =
            reasonLabel.parentElement?.querySelector('textarea');
        expect(reasonInput).not.toBeNull();
        fireEvent.change(reasonInput!, {
            target: {
                value: 'The approved Site coverage expectation is active again.',
            },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Reactivate' }));

        await waitFor(() => {
            expect(axios.post).toHaveBeenCalledWith(
                '/security-devices/settings/monitoring/coverage/71/reactivate',
                expect.objectContaining({
                    version: 2,
                    reason: 'The approved Site coverage expectation is active again.',
                }),
            );
        });
    });
});
