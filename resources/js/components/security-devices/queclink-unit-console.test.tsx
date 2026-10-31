import type { QueclinkUnitConsoleData } from '@/pages/security-devices/devices/device-profile';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { QueclinkUnitConsole } from './queclink-unit-console';

vi.mock('axios');

function buildConsoleFixture(
    overrides?: Partial<QueclinkUnitConsoleData>,
): QueclinkUnitConsoleData {
    return {
        fetch_url: '/security-devices/devices/2/queclink-console',
        command_url: '/security-devices/devices/2/queclink-console/commands',
        protocol_spec:
            'Queclink @Track Air Interface Protocol (GL300M / GL320MG / GV500CG)',
        family: 'GL30M',
        unit: {
            id: 7,
            imei_masked: '867963*****6998',
            status: 'paired',
            connection_state: 'connected',
            model_hint: 'GL320MG',
            protocol_version: '5E0100',
            firmware_version: 'R01A08V06',
            first_seen_at: '2026-10-01T00:00:00Z',
            last_seen_at: '2026-10-28T01:00:00Z',
            last_frame_at: '2026-10-28T01:00:00Z',
        },
        last_position: {
            state: 'consent_required',
            message:
                'Active resident location consent is required before coordinates can be shown.',
            latitude: null,
            longitude: null,
            accuracy_m: null,
            speed_kph: null,
            heading_deg: null,
            altitude_m: null,
            battery_pct: 84,
            occurred_at: '2026-10-28T01:00:00Z',
            address: null,
            map_url: null,
        },
        settings: {
            available: true,
            received_at: '2026-10-28T00:45:00Z',
            sections: ['SRI', 'DOG'],
            summary: {
                server: { primary_ip: '203.0.113.10', primary_port: 9001 },
                global: null,
                watchdog: { reboot_interval_days: 7, reboot_time: '0200' },
                non_movement: null,
                firmware: {
                    model: 'GL320MG',
                    firmware_version: 'R01A08V06',
                    protocol_version: '5E0100',
                },
            },
        },
        raw_messages: [
            {
                id: 101,
                direction: 'inbound',
                frame_type: 'position',
                command_word: '+RESP:GTFRI',
                parse_ok: true,
                parse_error: null,
                raw_frame:
                    '+RESP:GTFRI,5E0100,867963*****6998,,0,0,1,[REDACTED],[REDACTED],84,20261028010000,0012$',
                created_at: '2026-10-28T01:00:00Z',
            },
        ],
        recent_commands: [
            {
                id: 55,
                command_word: 'GTRTO',
                serial_number: '0011',
                status: 'acked',
                raw_command: 'AT+GTRTO=******,1,,,,,,0011$',
                ack_response: '+ACK:GTRTO,5E0100,867963*****6998,,0011$',
                failed_reason: null,
                created_by: 'Stephan',
                created_at: '2026-10-28T00:55:00Z',
                sent_at: '2026-10-28T00:55:05Z',
                acked_at: '2026-10-28T00:55:07Z',
                fulfilled_at: null,
                expires_at: '2026-10-29T00:55:00Z',
            },
        ],
        commands: {
            tracker_ready: true,
            unavailable_reason: null,
            can_send_safe: true,
            can_send_risky: true,
            sections: ['ALL', 'SRI', 'CFG', 'DOG'],
            supported: [
                {
                    key: 'request_location',
                    label: 'Request current position',
                    description: 'Ask the unit for an immediate GPS fix.',
                    at_preview: 'AT+GTRTO=<password>,1,,,,,,<serial>$',
                    spec_ref: '@Track Air Interface Protocol — AT+GTRTO sub-command 1',
                    risky: false,
                    enabled: true,
                },
                {
                    key: 'reboot',
                    label: 'Reboot unit',
                    description: 'Restart the tracker.',
                    at_preview: 'AT+GTRTO=<password>,3,,,,,,<serial>$',
                    spec_ref: '@Track Air Interface Protocol — AT+GTRTO sub-command 3',
                    risky: true,
                    enabled: true,
                },
            ],
        },
        ...overrides,
    };
}

describe('QueclinkUnitConsole', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders masked unit details, consent notice, settings, and redacted raw messages', () => {
        render(<QueclinkUnitConsole consoleData={buildConsoleFixture()} />);

        expect(screen.getByText('867963*****6998')).toBeVisible();
        expect(
            screen.getByText(
                'Active resident location consent is required before coordinates can be shown.',
            ),
        ).toBeVisible();
        expect(screen.getByText('84%')).toBeVisible();
        expect(screen.getByText('203.0.113.10')).toBeVisible();
        expect(
            screen.getByText(/\+RESP:GTFRI,5E0100,867963\*\*\*\*\*6998/),
        ).toBeVisible();
        expect(
            screen.getByText(/AT\+GTRTO=\*\*\*\*\*\*,1,,,,,,0011\$/),
        ).toBeVisible();
    });

    it('sends safe read-only queries directly and requires confirmation + reason for risky commands', async () => {
        vi.mocked(axios.post).mockResolvedValue({
            data: {
                message: 'Queued GTRTO (#0012) for delivery to the tracker.',
                console: buildConsoleFixture(),
            },
        });

        render(<QueclinkUnitConsole consoleData={buildConsoleFixture()} />);

        fireEvent.click(
            screen.getByRole('button', { name: 'Request current position' }),
        );
        await waitFor(() =>
            expect(axios.post).toHaveBeenCalledWith(
                '/security-devices/devices/2/queclink-console/commands',
                {
                    command: 'request_location',
                    parameters: {},
                    reason: null,
                    confirmed: false,
                },
            ),
        );

        vi.mocked(axios.post).mockClear();

        fireEvent.click(screen.getByRole('button', { name: 'Reboot unit…' }));
        expect(
            screen.getByRole('dialog', { name: /Confirm reboot unit/i }),
        ).toBeVisible();

        fireEvent.click(screen.getByRole('button', { name: 'Queue command' }));
        expect(
            screen.getByText(/at least 4 characters/i),
        ).toBeVisible();
        expect(axios.post).not.toHaveBeenCalled();

        fireEvent.change(screen.getByLabelText('Reason for audit log'), {
            target: { value: 'Unit stuck on stale cell tower' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Queue command' }));

        await waitFor(() =>
            expect(axios.post).toHaveBeenCalledWith(
                '/security-devices/devices/2/queclink-console/commands',
                {
                    command: 'reboot',
                    parameters: {},
                    reason: 'Unit stuck on stale cell tower',
                    confirmed: true,
                },
            ),
        );
    });
});
