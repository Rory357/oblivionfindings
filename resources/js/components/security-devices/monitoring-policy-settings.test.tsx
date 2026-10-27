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
    window.history.replaceState({}, '', '/security-devices/settings');
    vi.clearAllMocks();
    vi.mocked(axios.get).mockResolvedValue({ data: {} });
    vi.mocked(axios.post).mockResolvedValue({ data: {} });
    vi.mocked(axios.patch).mockResolvedValue({ data: {} });
});

describe('monitoring policy settings', () => {
    it('presents all eight policy tabs without raw target or secret controls', () => {
        render(<MonitoringPolicySettings workspace={workspace} />);

        for (const name of [
            'Overview',
            'Profiles',
            'Thresholds',
            'Alert routing',
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
        fireEvent.click(screen.getByRole('tab', { name: 'Profiles' }));
        fireEvent.click(
            await screen.findByRole('button', { name: 'Add profile' }),
        );
        const name = screen.getByLabelText('Profile name');
        fireEvent.change(name, { target: { value: 'Core reachability' } });
        fireEvent.click(
            screen.getByRole('button', { name: 'Save policy change' }),
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

    it('creates a scoped threshold rule with hysteresis thresholds', async () => {
        render(<MonitoringPolicySettings workspace={workspace} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Thresholds' }));
        fireEvent.click(
            await screen.findByRole('button', { name: 'Add threshold rule' }),
        );
        fireEvent.change(screen.getByLabelText('Rule name'), {
            target: { value: 'WAN latency guard' },
        });
        fireEvent.change(screen.getByLabelText('Warning trigger threshold'), {
            target: { value: '80' },
        });
        fireEvent.change(
            screen.getByLabelText('Warning clear threshold (hysteresis)'),
            {
                target: { value: '60' },
            },
        );
        fireEvent.change(screen.getByLabelText('Critical trigger threshold'), {
            target: { value: '150' },
        });
        fireEvent.change(
            screen.getByLabelText('Critical clear threshold (hysteresis)'),
            {
                target: { value: '110' },
            },
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Save policy change' }),
        );

        await waitFor(() => {
            expect(axios.post).toHaveBeenCalledWith(
                '/security-devices/settings/monitoring/thresholds',
                expect.objectContaining({
                    name: 'WAN latency guard',
                    scope_kind: 'application',
                    threshold_direction: 'above',
                    warning_rising_threshold: '80',
                    warning_falling_threshold: '60',
                    critical_rising_threshold: '150',
                    critical_falling_threshold: '110',
                }),
            );
        });
    });

    it('creates a technical alert route while displaying the life-safety floor notice', async () => {
        render(<MonitoringPolicySettings workspace={workspace} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Alert routing' }));
        fireEvent.click(
            await screen.findByRole('button', { name: 'Add alert route' }),
        );
        expect(
            screen.getAllByText(/stay locked to Control Room/i).length,
        ).toBeGreaterThan(0);
        fireEvent.change(screen.getByLabelText('Route name'), {
            target: { value: 'Core network IT routing' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Save policy change' }),
        );

        await waitFor(() => {
            expect(axios.post).toHaveBeenCalledWith(
                '/security-devices/settings/monitoring/alert-routes',
                expect.objectContaining({
                    name: 'Core network IT routing',
                    warning_notify_in_app: true,
                    warning_open_it_ticket: false,
                    critical_notify_email: true,
                    critical_open_it_ticket: true,
                    critical_escalate_control_room: false,
                }),
            );
        });
    });

    it('loads the winning policy preview for a selected monitor', async () => {
        vi.mocked(axios.get).mockResolvedValueOnce({
            data: {
                preview: {
                    monitor: {
                        id: 91,
                        name: 'Gateway reachability',
                        kind: 'icmp',
                        metric_key: 'latency_ms',
                    },
                    device: {
                        id: 41,
                        name: 'SD-WAN gateway',
                        domain: 'it_infrastructure',
                        category: 'network',
                        site_id: 14,
                    },
                    threshold: {
                        source_kind: 'site',
                        rule_id: 12,
                        rule_name: 'Coast WAN latency',
                        metric_key: 'latency_ms',
                        direction: 'above',
                        warning_rising: 80,
                        warning_falling: 60,
                        critical_rising: 150,
                        critical_falling: 110,
                    },
                    alert_route: {
                        source_kind: 'default_technical',
                        rule_id: null,
                        rule_name: 'Default technical routing',
                        life_safety_floor: false,
                        recipient_role: 'it_staff',
                        warning: {
                            notify_in_app: true,
                            notify_email: false,
                            open_it_ticket: false,
                        },
                        critical: {
                            notify_in_app: true,
                            notify_email: true,
                            open_it_ticket: true,
                            escalate_control_room: false,
                        },
                    },
                    maintenance: {
                        active_window: null,
                        upcoming_window: {
                            id: 5,
                            name: 'Sunday switch patching',
                            starts_at: '2026-10-27T11:00:00Z',
                            ends_at: '2026-10-27T13:00:00Z',
                            timezone: 'Pacific/Auckland',
                            life_safety_protected: false,
                        },
                    },
                    recipient_count: 3,
                },
            },
        });

        render(<MonitoringPolicySettings workspace={workspace} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Overview' }));
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Preview winning policy',
            }),
        );

        await waitFor(() => {
            expect(axios.get).toHaveBeenCalledWith(
                '/security-devices/settings/monitoring/effective-preview/91',
            );
        });
        expect(
            await screen.findByText('Coast WAN latency'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/Upcoming: Sunday switch patching/),
        ).toBeInTheDocument();
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
        fireEvent.click(screen.getByRole('tab', { name: 'Retention' }));
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Add retention policy',
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
            name: 'Save policy change',
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
        fireEvent.click(screen.getByRole('tab', { name: 'Coverage' }));
        fireEvent.keyDown(
            screen.getByRole('button', { name: /^Actions for/ }),
            { key: 'ArrowDown' },
        );
        fireEvent.click(
            await screen.findByRole('menuitem', { name: 'Reactivate' }),
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
