import { fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
    DebugConsoleTab,
    DeviceSettingsTab,
    default as QueclinkHub,
} from '@/pages/security-devices/integrations/queclink-hub';

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => (
        <main>{children}</main>
    ),
}));

const inertiaMocks = vi.hoisted(() => ({
    router: {
        post: vi.fn(),
        get: vi.fn(),
    },
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: React.ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: inertiaMocks.router,
    useForm: (initial: Record<string, unknown>) => ({
        data: initial,
        setData: vi.fn(),
        post: vi.fn(),
        processing: false,
        errors: {},
        reset: vi.fn(),
    }),
}));

const recentFrame = {
    id: 101,
    imei: '867963069916998',
    direction: 'inbound',
    frame_type: 'RESP',
    command_word: 'GTFRI',
    raw_frame:
        '+RESP:GTFRI,970204,867963069916998,,0,0,1,,,,,,,,0530,0001,A310,0017E102,19,0,4175,100,1,,,20260518020210,0082$',
    parse_ok: true,
    parse_error: null,
    created_at: '2026-05-18T02:07:01Z',
};

class EventSourceStub {
    static instances: EventSourceStub[] = [];

    onmessage: ((event: MessageEvent) => void) | null = null;
    onerror: (() => void) | null = null;
    close = vi.fn();

    constructor(public url: string) {
        EventSourceStub.instances.push(this);
    }
}

function renderHub() {
    return render(
        <DebugConsoleTab
            devices={[
                {
                    id: 2,
                    imei: '867963069916998',
                    status: 'paired',
                    model_hint: null,
                    protocol_version: '970204',
                    firmware_version: null,
                    connection_state: 'connected',
                    first_seen_at: null,
                    last_seen_at: '2026-05-18T02:07:01Z',
                    last_frame_at: '2026-05-18T02:07:01Z',
                    remote_address: null,
                    assignment: {
                        type: 'client',
                        target_id: 9012,
                        assigned_at: '2026-05-18T02:00:00Z',
                        label: 'Amelia Wilson',
                    },
                },
            ]}
            can={{ manage: true }}
        />,
    );
}

describe('QueclinkHub debug console', () => {
    beforeEach(() => {
        EventSourceStub.instances = [];
        vi.stubGlobal('EventSource', EventSourceStub);
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({ frames: [recentFrame] }),
            }),
        );
    });

    it('loads recent frames when the debug console opens instead of showing an empty stream', async () => {
        renderHub();

        expect(await screen.findByText(recentFrame.raw_frame)).toBeVisible();
        expect(fetch).toHaveBeenCalledWith(
            '/security-devices/integrations/queclink/frames',
            expect.objectContaining({
                headers: expect.objectContaining({
                    Accept: 'application/json',
                }),
            }),
        );
    });
});

describe('QueclinkHub page chrome', () => {
    it('uses the full-width Sites-style hero and tab rail', () => {
        render(
            <QueclinkHub
                listener={{
                    port: 8090,
                    public_hostname: 'oblivionfindings.com',
                    service_state: 'active',
                    connected_count: 1,
                }}
                devices={{
                    paired: [
                        {
                            id: 2,
                            imei: '867963069916998',
                            status: 'paired',
                            model_hint: 'GL30MEU',
                            protocol_version: '970204',
                            firmware_version: null,
                            connection_state: 'connected',
                            first_seen_at: null,
                            last_seen_at: '2026-05-18T02:07:01Z',
                            last_frame_at: '2026-05-18T02:07:01Z',
                            remote_address: null,
                            assignment: {
                                type: 'client',
                                target_id: 9012,
                                assigned_at: '2026-05-18T02:00:00Z',
                                label: 'Amelia Wilson',
                            },
                            configuration: null,
                            recent_commands: [],
                        },
                    ],
                    pending: [],
                    rejected: [],
                    total: 1,
                }}
                statistics={{
                    frames_last_hour: 12,
                    last_frame_at: '2026-05-18T02:07:01Z',
                }}
                imsCloud={null}
                targets={{
                    vehicles: [],
                    staff: [],
                    clients: [{ id: 9012, label: 'Amelia Wilson' }],
                }}
                can={{ manage: true }}
            />,
        );

        expect(screen.getByTestId('queclink-page-shell')).toHaveClass('w-full');
        expect(screen.getByTestId('queclink-hero')).toHaveClass(
            'bg-gradient-to-br',
        );
        expect(screen.getByText('Direct TCP listener')).toBeVisible();
        expect(screen.getByText('Paired')).toBeVisible();
        expect(screen.getByTestId('queclink-tab-list')).toHaveClass('border-b');
    });
});

describe('QueclinkHub device settings', () => {
    it('renders the latest config snapshot and safe GL30 controls', () => {
        inertiaMocks.router.post.mockClear();

        render(
            <DeviceSettingsTab
                can={{ manage: true }}
                listener={{
                    port: 8090,
                    public_hostname: 'oblivionfindings.com',
                    service_state: 'active',
                    connected_count: 1,
                }}
                devices={[
                    {
                        id: 2,
                        imei: '867963069916998',
                        status: 'paired',
                        model_hint: 'GL30MEU',
                        protocol_version: '970204',
                        firmware_version: null,
                        connection_state: 'connected',
                        first_seen_at: null,
                        last_seen_at: '2026-05-18T02:07:01Z',
                        last_frame_at: '2026-05-18T02:07:01Z',
                        remote_address: null,
                        assignment: {
                            type: 'client',
                            target_id: 9012,
                            assigned_at: '2026-05-18T02:00:00Z',
                            label: 'Amelia Wilson',
                        },
                        configuration: {
                            available: true,
                            received_at: '2026-05-18T03:15:00Z',
                            raw: 'SRI,3,0,1,oblivionfindings.com,8090,oblivionfindings.com,8090,,5,1,0,30,0,,CFG,,GL30MEU,150,08E3,006F,1,30,,0,1200,,1,,,,1,1,0000,,,10,1,,1,2,1,0',
                            sections: {},
                            summary: {
                                server: {
                                    main_host: 'oblivionfindings.com',
                                    main_port: '8090',
                                    backup_host: 'oblivionfindings.com',
                                    backup_port: '8090',
                                    heartbeat_interval_minutes: '5',
                                    sack_enable: '1',
                                    psm_network_hold_time_seconds: '30',
                                    report_mode: '3',
                                    manual_netreg: '0',
                                    buffer_mode: '1',
                                    sms_ack_enable: '0',
                                    protocol_format: '0',
                                },
                                global: {
                                    device_name: 'GL30MEU',
                                    gnss_timeout_seconds: '150',
                                    event_mask: '08E3',
                                    report_item_mask: '006F',
                                    mode_selection: '1',
                                    continuous_send_interval_seconds: '30',
                                    start_mode: '0',
                                    specified_time_of_day: '1200',
                                    wakeup_interval_hours: '1',
                                    gnss_enable: '1',
                                    agps_mode: '1',
                                    gsm_report: '0000',
                                    battery_low_percentage: '10',
                                    function_button_mode: '1',
                                    sos_report_mode: '1',
                                    wifi_report: '2',
                                    led_on: '1',
                                    charge_standby_mode: '0',
                                },
                                dog: {
                                    mode: '1',
                                    reboot_interval: '7',
                                    reboot_time: '0200',
                                },
                            },
                        },
                        recent_commands: [
                            {
                                id: 44,
                                command_word: 'GTDOG',
                                raw_command:
                                    'AT+GTDOG=gl30,1,,7,0200,,1,,0,,,60,0001$',
                                serial_number: '0001',
                                status: 'queued',
                                created_at: '2026-05-18T03:16:00Z',
                                sent_at: null,
                                acked_at: null,
                                cancelled_at: null,
                                expires_at: '2026-05-18T03:21:00Z',
                                failed_reason: null,
                                ack_response: null,
                            },
                            {
                                id: 45,
                                command_word: 'GTWFI',
                                raw_command:
                                    'AT+GTWFI=gl30,1,10,0,2,10,1,1,,,,0002$',
                                serial_number: '0002',
                                status: 'failed',
                                created_at: '2026-05-18T03:17:00Z',
                                sent_at: '2026-05-18T03:17:10Z',
                                acked_at: null,
                                cancelled_at: null,
                                expires_at: '2026-05-18T03:22:00Z',
                                failed_reason: 'expired',
                                ack_response: '+ACK:GTWFI,0,0002$',
                            },
                        ],
                    },
                ]}
            />,
        );

        expect(screen.getByText('Device settings')).toBeVisible();
        expect(screen.getByText('Connection health')).toBeVisible();
        expect(screen.getByText(/Amelia Wilson/)).toBeVisible();
        expect(
            screen.getAllByDisplayValue('oblivionfindings.com')[0],
        ).toBeVisible();
        expect(screen.getAllByDisplayValue('30')[0]).toBeVisible();
        expect(screen.getByText('Read full config')).toBeVisible();
        expect(screen.getByText('Read one section')).toBeVisible();
        expect(screen.getByText('Advanced GL30 sections')).toBeVisible();
        expect(screen.getByText('Resident safety profile')).toBeVisible();

        fireEvent.click(screen.getByRole('button', { name: 'Server' }));

        expect(inertiaMocks.router.post).toHaveBeenCalledWith(
            '/security-devices/integrations/queclink/devices/2/configuration/server/read',
            { command: 'SRI' },
            { preserveScroll: true },
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Queue Watchdog auto-reboot',
            }),
        );

        expect(inertiaMocks.router.post).toHaveBeenCalledWith(
            '/security-devices/integrations/queclink/devices/2/configuration/server',
            expect.objectContaining({
                command: 'dog',
                reboot_time: '0200',
            }),
            { preserveScroll: true },
        );

        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(inertiaMocks.router.post).toHaveBeenCalledWith(
            '/security-devices/integrations/queclink/commands/44/cancel',
            {},
            { preserveScroll: true },
        );

        fireEvent.click(screen.getByRole('button', { name: 'Retry' }));

        expect(inertiaMocks.router.post).toHaveBeenCalledWith(
            '/security-devices/integrations/queclink/commands/45/retry',
            {},
            { preserveScroll: true },
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Resident safety profile',
            }),
        );

        expect(inertiaMocks.router.post).toHaveBeenCalledWith(
            '/security-devices/integrations/queclink/devices/2/configuration/resident-safety-profile',
            {},
            { preserveScroll: true },
        );
    });
});
