import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
    DiscoveryScopeActionDialog,
    DiscoveryScopeDialog,
    type DiscoveryScopeManagement,
    type GovernedDiscoveryScope,
} from '@/components/security-devices/discovery-scope-dialogs';
import {
    NativeMonitorDeactivateDialog,
    NativeMonitorDialog,
    type NativeMonitorManagement,
    type NativeMonitorTarget,
} from '@/components/security-devices/native-monitor-dialogs';

vi.mock('axios');

const monitorManagement: NativeMonitorManagement = {
    can_manage: true,
    create_url: '/security-devices/monitoring/native-monitors',
    kinds: [{ value: 'icmp', label: 'ICMP availability' }],
    profiles: [{ id: 7, name: 'Standard policy' }],
    devices: [
        {
            id: 41,
            name: 'SD-WAN gateway',
            site: { id: 14, name: 'Coast Site' },
        },
    ],
};

const monitor: NativeMonitorTarget = {
    id: 91,
    name: 'Gateway reachability',
    kind: 'icmp',
    enabled: true,
    affects_availability: true,
    profile: { id: 7, name: 'Standard policy' },
    device: { id: 41, name: 'SD-WAN gateway' },
    actions: {
        can_manage: true,
        update_url: '/security-devices/monitoring/native-monitors/91',
        deactivate_url:
            '/security-devices/monitoring/native-monitors/91/deactivate',
    },
};

const scopeManagement: DiscoveryScopeManagement = {
    can_manage: true,
    create_url: '/security-devices/discovery/scopes',
    protocols: ['icmp', 'tcp', 'dns', 'tls', 'snmp'],
    sites: [{ id: 14, name: 'Coast Site' }],
};

const scope: GovernedDiscoveryScope = {
    id: 33,
    name: 'Primary Site networks',
    status: 'active',
    site: { id: 14, name: 'Coast Site' },
    collection_mode: 'direct',
    protocols: ['icmp', 'tcp'],
    max_targets_per_run: 1024,
    packets_per_second: 20,
    actions: {
        can_manage: true,
        update_url: '/security-devices/discovery/scopes/33',
        apply_url: '/security-devices/discovery/scopes/33/apply',
        deactivate_url: '/security-devices/discovery/scopes/33/deactivate',
    },
};

beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(axios.post).mockResolvedValue({ data: {} });
    vi.mocked(axios.patch).mockResolvedValue({ data: {} });
});

describe('native monitoring operator workflows', () => {
    it('creates a central direct monitor without placing its target in navigation state', async () => {
        const onSaved = vi.fn();
        render(
            <NativeMonitorDialog
                open
                onOpenChange={vi.fn()}
                management={monitorManagement}
                monitor={null}
                onSaved={onSaved}
            />,
        );

        fireEvent.change(screen.getByLabelText('Monitor name'), {
            target: { value: 'Gateway reachability' },
        });
        fireEvent.change(screen.getByLabelText('Approved target'), {
            target: { value: '10.44.0.10' },
        });
        await waitFor(() => {
            expect(
                screen.getByRole('button', { name: 'Create direct monitor' }),
            ).toBeEnabled();
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Create direct monitor' }),
        );

        await waitFor(() => {
            expect(axios.post).toHaveBeenCalledWith(
                monitorManagement.create_url,
                expect.objectContaining({
                    device_id: 41,
                    profile_id: 7,
                    kind: 'icmp',
                    name: 'Gateway reachability',
                    target: '10.44.0.10',
                }),
            );
        });
        expect(onSaved).toHaveBeenCalledOnce();
    });

    it('updates safe monitor fields while blank target preserves the hidden definition', async () => {
        render(
            <NativeMonitorDialog
                open
                onOpenChange={vi.fn()}
                management={monitorManagement}
                monitor={monitor}
                onSaved={vi.fn()}
            />,
        );

        fireEvent.change(screen.getByLabelText('Monitor name'), {
            target: { value: 'Gateway availability' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Apply monitor update' }),
        );

        await waitFor(() => {
            expect(axios.patch).toHaveBeenCalledWith(
                monitor.actions.update_url,
                expect.not.objectContaining({ target: expect.anything() }),
            );
        });
    });

    it('requires an operational reason before dependency-safe monitor deactivation', async () => {
        render(
            <NativeMonitorDeactivateDialog
                open
                onOpenChange={vi.fn()}
                monitor={monitor}
                onDeactivated={vi.fn()}
            />,
        );

        const button = screen.getByRole('button', {
            name: 'Deactivate monitor',
        });
        expect(button).toBeDisabled();
        fireEvent.click(
            screen.getByRole('combobox', { name: 'Operational reason' }),
        );
        fireEvent.click(
            screen.getByRole('option', {
                name: 'Replaced by an approved definition',
            }),
        );
        fireEvent.click(button);

        await waitFor(() => {
            expect(axios.post).toHaveBeenCalledWith(
                monitor.actions.deactivate_url,
                { reason_code: 'replaced' },
            );
        });
    });

    it('creates a bounded direct discovery scope and never offers a collector field', async () => {
        render(
            <DiscoveryScopeDialog
                open
                onOpenChange={vi.fn()}
                management={scopeManagement}
                scope={null}
                onSaved={vi.fn()}
            />,
        );

        expect(screen.queryByLabelText(/collector/i)).not.toBeInTheDocument();
        fireEvent.change(screen.getByLabelText('Scope name'), {
            target: { value: 'Primary Site networks' },
        });
        fireEvent.change(screen.getByLabelText('Approved networks'), {
            target: { value: '10.44.0.0/16' },
        });
        await waitFor(() => {
            expect(
                screen.getByRole('button', { name: 'Create direct scope' }),
            ).toBeEnabled();
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Create direct scope' }),
        );

        await waitFor(() => {
            expect(axios.post).toHaveBeenCalledWith(
                scopeManagement.create_url,
                expect.objectContaining({
                    site_id: 14,
                    cidrs: ['10.44.0.0/16'],
                    protocols: ['icmp', 'tcp', 'dns', 'tls'],
                    port_bounds: {
                        tcp: [22, 80, 443, 161, 5985, 5986],
                        dns: [53],
                        tls: [443],
                    },
                    max_targets_per_run: 1024,
                    packets_per_second: 20,
                }),
            );
        });
    });

    it('queues one governed scope run through the explicit apply action', async () => {
        render(
            <DiscoveryScopeActionDialog
                open
                onOpenChange={vi.fn()}
                scope={scope}
                action="apply"
                onApplied={vi.fn()}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Queue discovery run' }),
        );

        await waitFor(() => {
            expect(axios.post).toHaveBeenCalledWith(
                scope.actions.apply_url,
                {},
            );
        });
    });
});
