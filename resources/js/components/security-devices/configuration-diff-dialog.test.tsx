import { render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
    ConfigurationDiffDialog,
    type ConfigurationComparisonPayload,
} from './configuration-diff-dialog';

vi.mock('axios');

function buildComparisonPayload(): ConfigurationComparisonPayload {
    return {
        device: {
            id: 19,
            name: 'Core Switch A',
            uid: 'SW-CORE-19',
        },
        target: {
            id: 52,
            source_kind: 'running_config',
            source_label: 'Running configuration',
            source: 'collector',
            captured_at: '2026-10-26T06:00:00Z',
            firmware_version: '17.09.04a',
            configuration_hash: 'a1b2c3d4e5f60718293a4b5c',
            content_size: 2048,
            download_href:
                '/security-devices/devices/19/configuration-snapshots/52',
        },
        baseline: {
            id: 51,
            source_kind: 'running_config',
            source_label: 'Running configuration',
            source: 'collector',
            captured_at: '2026-10-25T06:00:00Z',
            firmware_version: '17.09.03',
            configuration_hash: '99887766554433221100aabb',
            content_size: 1980,
            download_href:
                '/security-devices/devices/19/configuration-snapshots/51',
        },
        identical: false,
        summary: {
            added_fields: 1,
            removed_fields: 0,
            changed_fields: 1,
            added_lines: 2,
            removed_lines: 1,
        },
        field_changes: [
            {
                kind: 'changed',
                path: 'vlans.20.name',
                before: 'Staff_Legacy',
                after: 'Staff_Voice',
            },
            {
                kind: 'added',
                path: 'ntp.servers.1',
                before: null,
                after: '10.20.0.10',
            },
        ],
        field_changes_truncated: false,
        line_diff: [
            {
                kind: 'context',
                old_line: 10,
                new_line: 10,
                content: 'enable secret [REDACTED]',
            },
            {
                kind: 'removed',
                old_line: 11,
                new_line: null,
                content: 'vlan 20 name Staff_Legacy',
            },
            {
                kind: 'added',
                old_line: null,
                new_line: 11,
                content: 'vlan 20 name Staff_Voice',
            },
            {
                kind: 'added',
                old_line: null,
                new_line: 12,
                content: 'ntp server 10.20.0.10',
            },
        ],
        line_diff_truncated: false,
        line_source: 'running_config',
        available_snapshots: [
            {
                id: 52,
                source_kind: 'running_config',
                source_label: 'Running configuration',
                source: 'collector',
                captured_at: '2026-10-26T06:00:00Z',
                firmware_version: '17.09.04a',
                configuration_hash: 'a1b2c3d4e5f60718293a4b5c',
                content_size: 2048,
                download_href:
                    '/security-devices/devices/19/configuration-snapshots/52',
            },
            {
                id: 51,
                source_kind: 'running_config',
                source_label: 'Running configuration',
                source: 'collector',
                captured_at: '2026-10-25T06:00:00Z',
                firmware_version: '17.09.03',
                configuration_hash: '99887766554433221100aabb',
                content_size: 1980,
                download_href:
                    '/security-devices/devices/19/configuration-snapshots/51',
            },
        ],
    };
}

describe('ConfigurationDiffDialog', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders redacted unified line diffs and structured field changes', async () => {
        vi.mocked(axios.get).mockResolvedValueOnce({
            data: buildComparisonPayload(),
        });

        render(
            <ConfigurationDiffDialog
                target={{
                    deviceId: 19,
                    deviceName: 'Core Switch A',
                    snapshotId: 52,
                    baselineSnapshotId: 51,
                }}
                onClose={vi.fn()}
            />,
        );

        await waitFor(() => {
            expect(axios.get).toHaveBeenCalledWith(
                '/security-devices/devices/19/configuration-snapshots/52/compare/51',
                expect.objectContaining({
                    headers: { Accept: 'application/json' },
                }),
            );
        });

        expect(
            await screen.findByText('Configuration changed'),
        ).toBeInTheDocument();
        expect(screen.getByText('Secrets redacted')).toBeInTheDocument();
        expect(
            screen.getByText('enable secret [REDACTED]'),
        ).toBeInTheDocument();
        expect(screen.getByText('vlans.20.name')).toBeInTheDocument();
        expect(screen.getByText('vlan 20 name Staff_Voice')).toBeInTheDocument();
    });

    it('renders a clear permission notice when snapshot comparison returns 403', async () => {
        vi.mocked(axios.get).mockRejectedValueOnce({
            response: { status: 403 },
        });

        render(
            <ConfigurationDiffDialog
                target={{
                    deviceId: 19,
                    deviceName: 'Core Switch A',
                    snapshotId: 52,
                }}
                onClose={vi.fn()}
            />,
        );

        expect(
            await screen.findByText(
                'You do not have permission to view or compare configuration backups for this device.',
            ),
        ).toBeInTheDocument();
    });
});
